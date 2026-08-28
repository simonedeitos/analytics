# AnalyticsPRO

Webapp PHP/PDO multi-tenant per importare dati catastali, salvarli su MySQL/MariaDB e visualizzarli su mappa, report e analitiche.

**Requisiti PHP**: **PHP 8.0+** (il codice usa `match()`, `str_contains()`, `catch (Throwable)` senza variabile e altri costrutti 8.0+).

## Setup rapido

1. Copia `analyticspro/.env.example` in `analyticspro/.env` e configura:
   - MySQL/MariaDB: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
   - Bootstrap encryption key: `APP_BOOTSTRAP_ENCRYPTION_KEY`
2. Esegui gli schemi SQL:
   ```bash
   mysql -u USER -p DBNAME < analyticspro/sql/schema.sql
   mysql -u USER -p DBNAME < analyticspro/sql/cadastral_geometry.sql
   ```
3. Apri `analyticspro/setup/create_admin.php`, genera la query del primo admin ed eseguila manualmente sul database.
4. **Elimina `analyticspro/setup/create_admin.php` dopo l'uso**.
5. Accedi come admin e completa:
   - configurazione SMTP (`smtp_*` salvati in `system_config`)
   - `admin_notification_email`
   - eventuale `system_config.encryption_key` se vuoi sostituire la chiave bootstrap da `.env`

## Import dati utente

- Formati supportati: `.csv`, `.xlsx`, `.xls`
- Parsing client-side con SheetJS, sul modello dell'app analytics root.
- Persistenza server-side con PDO prepared statements.
- I duplicati su chiave catastale `(user_id, provincia, comune, sezione, foglio, particella, subalterno)` vengono analizzati prima dell'import.
- Se cambia l'intestatario, il worker crea lo storico su `property_owners` e registra la scelta in `import_duplicate_conflicts`.

### Flusso a due fasi: persistenza immediata + arricchimento coordinate asincrono

L'import CSV/Excel è suddiviso in due fasi indipendenti:

**Fase 1 — Persistenza dati (sincrona, sempre garantita)**  
`api/data/import.php` chiama direttamente `analyticspro_process_import_batch_payload()` nella
stessa richiesta HTTP, scrivendo **tutte** le righe del file in `properties` con
`lat = NULL`, `lng = NULL`, `posizione_verificata = 0`, senza effettuare alcuna chiamata di rete.
Tutti i dati catastali vengono salvati prima che la risposta HTTP venga inviata al browser;
l'esito (righe salvate o messaggio d'errore) è quindi immediato e certo.  
Il file `storage/import_payloads/import_<id>.json` viene comunque scritto su disco per consentire
la ri-esecuzione manuale/diagnostica tramite `cron/process_import_batch.php` se necessario.

**Fase 2 — Arricchimento coordinate (in background)**  
Subito dopo il completamento della Fase 1 il worker `cron/enrich_property_coordinates.php`
viene lanciato in background tramite `analyticspro_launch_background()`. Questo worker:

1. Seleziona da `properties` le righe con `lat IS NULL` per il batch appena importato.
2. **Deduplica per particella unica** (chiave `provincia|comune|sezione|foglio|particella`):
   30 righe con 18 particelle uniche producono al massimo 18 chiamate WFS, non 30.
3. Per ogni particella chiama `analyticspro_wfs_query_service()` (con rate-limiting ≥ 0.5 s
   tra chiamate live e cache SQLite locale).
4. Applica le coordinate a **tutte** le righe che condividono quella particella.
5. Registra il progresso in `import_batches.enrichment_status`,
   `enrichment_processed`, `enrichment_total`.
6. Isola gli errori per singola particella senza abortire l'arricchimento.

Se `proc_open`/`shell_exec` non sono disponibili (hosting limitati), il batch rimane con
`enrichment_status = 'pending'` e viene recuperato dal **cron di recupero batch orfani**
(vedi sezione *Cron di recupero batch orfani*). La Fase 1 è sempre atomicamente completata
prima di qualsiasi chiamata di rete, e la risposta HTTP torna sempre immediatamente al browser.

## Cifratura dati sensibili

- Campo chiave: `system_config.encryption_key`, con fallback `APP_BOOTSTRAP_ENCRYPTION_KEY`.
- Algoritmo: AES-256-CBC (`analyticspro/config/encryption.php`).
- Hash di ricerca esatta: SHA-256 per nome/cognome/codice fiscale/telefono.

## SMTP

- Configurabile da dashboard admin.
- Pulsante “Testa connessione” basato su handshake SMTP socket.
- Le notifiche registrazione/subutente usano la configurazione presente in `system_config` con fallback `.env`.

## Cartografia ADE — tabelle MySQL

Le geometrie catastali (particelle, comuni) sono memorizzate in **tabelle dedicate
nello stesso database MySQL/MariaDB applicativo** — non è necessario alcun database
PostGIS esterno.

### Schema

```bash
mysql -u USER -p DBNAME < analyticspro/sql/schema.sql
mysql -u USER -p DBNAME < analyticspro/sql/cadastral_geometry.sql
```

Le tabelle aggiunte sono:

| Tabella | Descrizione |
|---|---|
| `cadastral_comuni` | Un record per comune importato (collegato al job ADE) |
| `cadastral_parcels` | Geometrie delle particelle (`GEOMETRY SRID 4326`) con `interior_point` precalcolato |
| `cadastral_parcel_verification` | Log di verifica della posizione marker |

Richiede **MySQL 8.0+** o **MariaDB 10.5+** per il supporto a `GEOMETRY` con SRID e `SPATIAL INDEX`.

### Lookup coordinate

> **⚠️ Comportamento aggiornato:** la funzione `analyticspro_lookup_cadastral_coordinates()` in
> `includes/importer.php` **non interroga più** `cadastral_parcels.interior_point` (tabella
> popolata dal bulk import ADE, ora disabilitato).  
> Utilizza invece il lookup on-demand via WFS pubblico AdE INSPIRE (vedi sezione
> *Lookup WFS on-demand* più avanti).

La logica condivisa di lookup è centralizzata in `includes/wfs_lookup.php`
(`analyticspro_wfs_lookup_particella()`) ed è usata sia dall'endpoint HTTP
`api/get_coords_wfs.php` sia dal worker di import CSV/Excel.

---

### Lookup WFS on-demand

La funzione `analyticspro_wfs_lookup_particella(codCatastale, foglio, particella)` in
`includes/wfs_lookup.php` esegue la seguente sequenza:

1. **Cache locale SQLite** (`cache/catasto/catasto_cache.db`, tabella `particelle_cache`):
   se il record è già in cache, viene restituito immediatamente senza chiamate di rete.
2. **WFS pubblico AdE INSPIRE** (`https://wfs.cartografia.agenziaentrate.gov.it/inspire/wfs/ows01.php`):
   se non in cache, interroga il servizio live, calcola il centroide dalla geometria GeoJSON
   e salva il risultato in cache per richieste future.
3. **Fallimento isolato**: se il WFS non risponde o la particella non esiste, la funzione
   ritorna `['ok' => false, ...]`; il worker di import continua con le righe successive
   segnando la riga come "coordinate non trovate" (nessun abort dell'import).

**Rate-limiting**: durante l'import bulk, il worker applica un gap minimo di 500 ms tra
chiamate live consecutive al WFS per evitare blocchi da parte del servizio pubblico.
Le hit di cache non sono soggette a questo ritardo.

## Worker ADE

> **⚠️ Sezione nascosta dalla UI admin:** la voce "Import ADE" è stata rimossa dalla
> dashboard admin e dalla sub-nav (file `admin/index.php` e `admin/_admin_subnav.php`)
> in favore del nuovo flusso di lookup on-demand via WFS (vedi sezione *Lookup WFS on-demand*).
> Il codice sottostante (`admin/import_ade.php`, `api/admin/ade_jobs.php`,
> `cron/ade_import_worker.php`, `includes/ade_import.php`, tabelle `ade_import_jobs` /
> `ade_import_job_log`) è mantenuto intatto per continuità con job storici e per poter
> riattivare la funzionalità in futuro accedendo direttamente via URL.

- Endpoint admin: `analyticspro/api/admin/ade_jobs.php`
- Worker CLI: `analyticspro/cron/ade_import_worker.php`
- Sezione admin dedicata: `analyticspro/admin/import_ade.php`
- Il worker estrae ricorsivamente ZIP annidati e fa parsing delle particelle solo dai GML `*_ple.gml` (`*_map.gml` è rilevato ma ignorato per l'import particelle)
- Ogni `cp:CadastralParcel` valido viene importato in `cadastral_parcels` con:
  - `geom` da poligono esterno (fori interni ignorati in questa versione)
  - `interior_point` calcolato localmente (centroide + fallback point-in-polygon)
  - `cadastral_parcel_verification.metodo = 'interior_point'`, `verificato = 0`
- Il parsing dei riferimenti catastali (`sezione/foglio/particella`) è tollerante; particelle non parsabili vengono saltate con warning nei log job
- La verifica live contro servizi ADE esterni non è ancora implementata (TODO)

## Note operative

- Sessioni PHP in tabella `user_sessions`
- Remember me massimo 10 ore
- CSRF su form e API JSON
- Il telefono resta nascosto se `can_view_phone = 0` per il tenant
- I subutenti non possono eliminare dati e, senza `can_edit_all_markers`, possono modificare solo immobili assegnati

## Import ADE: caricamento via FTP / file manager

Per file ZIP di grandi dimensioni (o quando l'upload HTTP diretto non è pratico),
è possibile caricare i file sul server tramite FTP o file manager nella cartella:

```
analyticspro/storage/manual_upload/
```

Poi nella pagina **Admin → Import cartografia ADE**, seleziona il tab
**"File già presenti sul server"**: la lista dei file ZIP presenti in
`storage/manual_upload/` viene mostrata automaticamente. Spunta i file desiderati
e clicca **"Importa selezionati"**: i file vengono spostati in `storage/ade_uploads/`
e processati esattamente come gli upload diretti da browser.

Il tab **"Upload dal browser"** rimane disponibile per i file di piccole dimensioni.

## Import ADE: file SQL pre-elaborati

Per import molto grandi puoi evitare il parsing GML lato server generando prima un
file `.sql` offline con il tool companion `tools/ADEtoDB/`.

Nella pagina **Admin → Import cartografia ADE** è disponibile il terzo tab
**"Importa file SQL pre-elaborato"**, che supporta due flussi:

1. caricamento diretto del file `.sql` dal browser
2. selezione di file `.sql` già copiati in `analyticspro/storage/manual_upload/`

Il worker SQL esegue gli statement in background, aggiorna `ade_import_job_log`
con l'avanzamento e continua anche in presenza di errori su singoli statement,
tracciandoli nel log del job.

### Tool Windows Forms ADEtoDB

La solution Visual Studio è inclusa nel repository:

```text
tools/ADEtoDB/
```

ADEtoDB estrae localmente gli ZIP ADE, interpreta i GML con la stessa logica del
parser PHP (`msGeometry`, campi maiuscoli, riferimenti catastali con sezione+foglio
attaccati) e genera SQL compatibile con:

- `cadastral_comuni`
- `cadastral_parcels`
- `ST_GeomFromText(wkt, 4326)` a **2 parametri**

Consulta `tools/ADEtoDB/README.md` per build e utilizzo end-to-end.

### Log live

Subito dopo aver avviato un import (da entrambe le modalità) si apre automaticamente
una finestra modale con il **log in tempo reale** del job. Puoi riaprirla in qualsiasi
momento cliccando il pulsante <kbd><i class="bi bi-terminal"></i></kbd> accanto a
ciascun job nella tabella "Job recenti".

---

## Geolocalizzazione particelle: Zornade API v2 + fallback WFS

AnalyticsPRO geolocalizza ogni immobile (da foglio/particella catastale a lat/lng) usando
**Zornade API v2** come provider primario, con fallback automatico al **WFS pubblico dell'Agenzia
delle Entrate** (INSPIRE) se Zornade non è configurato o non trova la particella.

### Come configurare Zornade API v2

1. Registrati su [https://zornade.com](https://zornade.com) e genera il tuo token API personale
   (scope richiesto: `parcels:read`).
2. Apri il file `.env` sul server (non committare questo file nel repository):
   ```
   ZORNADE_API_KEY=zrn_<il-tuo-token-reale>
   ZORNADE_API_BASE_URL=https://api.zornade.com/api/v2
   ```
   > **Nota**: la base URL corretta per API v2 è `https://api.zornade.com/api/v2`.
   > Il valore precedente `https://app.zornade.com/api` era errato.
3. Riavvia/ricarica l'applicazione. Il worker di arricchimento userà automaticamente Zornade.

> **Importante**: il token non deve mai comparire nel codice sorgente, nei log applicativi,
> né in risposte JSON verso il client. Solo il file `.env` sul server deve contenerlo.

### Autenticazione API v2

L'autenticazione avviene **esclusivamente** tramite l'header `x-api-key: <token>`.
**Non usare mai** `Authorization: Bearer` su API v2 — la documentazione ufficiale lo vieta
esplicitamente (la risposta sarebbe `UNAUTHORIZED_NO_AUTH_HEADER` o `UNAUTHORIZED_INVALID_JWT_FORMAT`).

### Mapping campi Zornade → dati catastali

| Campo Zornade    | Dato catastale AnalyticsPRO | Note |
|------------------|-----------------------------|------|
| `comune_code`    | Codice catastale Belfiore   | es. "H501" per Roma — risolto da comune+provincia tramite `comuni_catastali.json` |
| `foglio`         | Foglio                      | numero foglio |
| `label`          | Particella                  | numero/lettera particella visibile sulla mappa catastale |
| `sezione_urbana` | Sezione catastale           | opzionale |

### Rate limiting (Zornade API v2)

- **Limite ufficiale**: 1.000 richieste/ora per token (`X-RateLimit-Limit` lo conferma).
- **Gap applicato**: ~500 ms tra chiamate live consecutive, come precauzione pratica per batch
  di poche decine di particelle. Non è un tentativo di distribuire esattamente il quota oraria:
  con pochi import cliente al giorno il limite di 1.000 req/h non dovrebbe mai essere raggiunto.
- Se la risposta è `429`, il campo `retry_after_seconds` nel body JSON viene rispettato
  (attesa progressiva, non sleep fisso).
- **WFS-AdE**: gap minimo di 500 ms tra chiamate live (comportamento invariato).

### Comportamento di fallback

| Scenario | Azione |
|---|---|
| `ZORNADE_API_KEY` vuoto o assente | salta Zornade, usa WFS-AdE direttamente |
| `comune_code` non risolvibile | log `[Zornade]`, usa WFS-AdE |
| Zornade risponde HTTP non-200 | log `[Zornade]` con codice errore e messaggio, usa WFS-AdE |
| Zornade non trova la particella | usa WFS-AdE |
| Entrambi i provider falliscono | la proprietà rimane senza lat/lng (importazione non bloccata) |

### Cache locale

Tutti i risultati (sia Zornade che WFS-AdE) vengono salvati in un database SQLite locale
(`cache/catasto/catasto_cache.db`, tabella `particelle_cache`, colonna `source` con il
provider che ha risolto la particella). Le chiamate successive per la stessa particella
non richiedono alcuna richiesta HTTP.

La cache è indicizzata per `(cod_catastale, foglio, particella)`, dove `cod_catastale` è
il codice Belfiore anche per i risultati Zornade — la cache è quindi condivisa tra i due provider.

### Flusso a due fasi: persistenza immediata + arricchimento coordinate

**Fase 1 — Persistenza dati (sincrona, sempre garantita)**  
`api/data/import.php` chiama direttamente `analyticspro_process_import_batch_payload()` nella
stessa richiesta HTTP, scrivendo **tutte** le righe del file in `properties` con
`lat = NULL`, `lng = NULL`, `posizione_verificata = 0`, senza effettuare alcuna chiamata di rete.
La risposta HTTP torna sempre al browser subito dopo la Fase 1, indipendentemente dall'esito
del worker di arricchimento.

**Fase 2 — Arricchimento coordinate (background/cron/sincrono)**  
Subito dopo la Fase 1, `analyticspro_launch_background()` tenta di avviare
`cron/enrich_property_coordinates.php` in background.

**Fallback sincrono a lotti**: se il background worker **non può partire** (hosting che non
supporta `proc_open`/`shell_exec`), la colonna `import_batches.enrichment_sync` viene impostata
a `1` e la risposta include `enrichment_sync: true`. Il frontend rileva questa condizione e
chiama ripetutamente `api/data/enrich_chunk.php?batch_id=X&limit=25`, elaborando 25 particelle
per ogni chiamata di polling. In questo modo l'arricchimento procede senza mai bloccare il browser
e la barra di avanzamento si aggiorna ad ogni chunk.

**Watchdog anti-stallo**: se dopo 6 poll (~15 secondi) lo stato è ancora `pending` con
`enrichment_processed = 0`, il frontend commuta automaticamente alla modalità chunk sincrona
anche senza `enrichment_sync: true` (utile se il segnale si perde).

La risposta HTTP torna comunque immediatamente — non c'è mai alcun blocco del browser.

### Catena di risoluzione del codice Belfiore

Quando una riga importata non contiene un `cod_catastale` valido, AnalyticsPRO prova in quest'ordine:

1. `cod_catastale` esplicito nella riga (`^[A-Z]\d{3}$`)
2. **Catalogo GML locale** (`analyticspro_gml_belfiore_da_comune`) usando il nome comune estratto dai filename caricati
3. `data/comuni_catastali.json` tramite `analyticspro_wfs_lookup_cod_catastale()`
4. tabella MySQL `cadastral_comuni`

La normalizzazione del nome comune è condivisa e aggressiva: maiuscole, trim, collasso spazi,
rimozione accenti/apostrofi, `_`/`-` → spazio, supporto a abbreviazioni comuni come `D/` → `DELLE`,
`S.`/`S ` → `SAN`, `SS.` → `SANTI`, `ST.` → `SANTO`.

### Cron di recupero batch orfani (consigliato su hosting senza proc_open)

Su hosting che non supporta `proc_open` o `shell_exec`, l'arricchimento avviene già in modo
sincrono tramite il fallback a chunk (vedi sopra). In aggiunta, per sicurezza, configura il
cron di recupero così da gestire eventuali batch non risolti (ad esempio dopo un riavvio del
server):

```
* * * * * php /percorso/assoluto/analyticspro/cron/enrich_pending_batches.php >> /percorso/log/enrich_pending.log 2>&1
```

Sostituisci `/percorso/assoluto/` con il percorso reale sul tuo server.

Il cron seleziona tutti i batch con `enrichment_status = 'pending'` (worker non partito)
o `enrichment_status = 'processing'` da più di 15 minuti (worker morto a metà) e li elabora
in sequenza. Un batch che fallisce non blocca quelli successivi (error isolation).

### Stato di arricchimento nella UI

Dopo il completamento dell'import, nella pagina **Importa** compare automaticamente una
barra di avanzamento con il testo "Geolocalizzazione: X/Y marker" che si aggiorna in
tempo reale. In caso di errore compare il messaggio d'errore specifico con indicazioni per
il recupero.

### Recupero delle coordinate mancanti

La pagina **Importa** espone un pulsante **"Rigenera coordinate mancanti"** che richiama
`api/data/enrich_chunk.php?batch_id=0` (modalità globale) a chunk ripetuti, elaborando
tutte le righe con `lat IS NULL` indipendentemente dal batch di origine. Utile dopo:
- aver caricato nuovi file GML e costruito l'indice
- aver configurato Zornade o WFS
- un arricchimento parziale interrotto

### Diagnostica: health check Zornade

La funzione `analyticspro_zornade_health_check()` in `includes/zornade_lookup.php`
chiama `GET /health` (nessuna autenticazione richiesta) e ritorna lo status/versione del
servizio Zornade. Utile per un futuro pulsante "Testa connessione Zornade" in admin.

---

## Import GML locale

### Scopo

Sostituisce il lookup on-demand via WFS pubblico dell'Agenzia delle Entrate (fragile:
HTTP 403 dal WAF Akamai, rate-limit, lento) con un **repository locale di file GML ufficiali ADE**.

### Struttura delle cartelle

```
analyticspro/storage/gml/            ← file GML appiattiti (deny HTTP via .htaccess)
analyticspro/storage/gml_index/      ← indici JSON e SQLite (deny HTTP via .htaccess)
  catalogo.json                      ← BELFIORE → {ple, map, nome, size, mtime}
  {BELFIORE}_fogli.json              ← indice codici foglio dal _map.gml
  {BELFIORE}.sqlite                  ← indice particelle per lookup O(1)
```

### Procedura operativa per l'admin

1. Andare su **Admin → Import GML**.
2. Trascinare le cartelle dei file GML (anche più cartelle contemporaneamente, struttura annidata).
   Sono accettati file `*_ple.gml` / `*_map.gml` e archivi `.zip`.
   Ogni ulteriore trascinamento **accumula** i file invece di sovrascrivere la selezione precedente.
3. Premere **Carica e rigenera catalogo** — il catalogo viene rigenerato automaticamente.
4. Per ogni comune caricato, premere **Indicizza tutti i comuni** (o il pulsante per singolo comune)
   per avviare l'indicizzazione in background. Un log live mostra il progresso comune per comune.
   L'indicizzazione è **atomica**: un indice è considerato valido solo se la costruzione
   è terminata con successo (flag `meta['complete']` nel database SQLite).
5. Usare lo strumento **Diagnostica** per verificare che il numero di feature lette coincida
   con l'attributo `numberMatched` del GML.
   Nota: l'analisi può richiedere alcuni minuti sui comuni grandi.

### Catena di risoluzione coordinate (ordine di priorità)

Al momento dell'arricchimento coordinate di ogni riga importata:

1. **GML locale** (`analyticspro_gml_lookup`) — offline, O(1), sorgente: `gml_locale`
2. **Cache SQLite** (`cache/catasto/catasto_cache.db`) — sorgente: `cache`
3. **Zornade** (se configurato) — sorgente: `zornade`
4. **WFS on-demand ADE** (fallback finale, rate-limit 500 ms) — sorgente: `wfs`
5. Fallimento → riga marcata senza coordinate, import NON interrotto

Se una particella non viene risolta, il batch registra anche un report strutturato in
`import_batches.enrichment_report` con:

- conteggi per `coord_source`
- conteggi per motivo di fallimento (`comune_non_risolto`, `dati_incompleti`, `gml_mancante`,
  `comune_non_indicizzato`, `foglio_inesistente`, `particella_inesistente`, `provider_remoto_fallito`)
- elenco troncato delle righe non risolte nel formato `Comune F.x P.y — motivo`

Il report è esposto da `api/data/import_progress.php` ed è mostrato nella UI di `importa.php`
accanto alla barra di avanzamento dell'arricchimento.

La sorgente è salvata nel campo `coord_source` della tabella `properties`
(aggiunto dalla migration `004_add_coord_source_to_properties.sql`).

### Strategia di lookup GML (`analyticspro_gml_lookup`)

`analyticspro_gml_lookup()` applica tre livelli progressivi di ricerca:

1. **Indice SQLite — foglio esatto + particella esatta**  
   Ricerca O(1) sul campo `parcels.cod_foglio` e `parcels.particella`.

2. **Indice SQLite — foglio esatto + particella normalizzata**  
   Se la particella ha zero-padding (`0147`) o suffisso lettera (`147/A`),
   il match avviene tramite `parcels.particella_norm` pre-calcolato.

3. **Indice SQLite — variante allegato/sviluppo (primi 4 caratteri)**  
   Se il `cod_foglio` calcolato non esiste nell'indice (es. si cerca `003300`
   ma il GML contiene `0033A0`), la ricerca si restringe alle righe con
   `substr(cod_foglio, 1, 4) = '0033'` e particella coincidente.
   Questo gestisce le varianti allegato/sviluppo senza richiedere all'utente
   di conoscere il formato esatto usato nel GML.

4. **Ricerca streaming diretta sul `_ple.gml`** (solo se indice non disponibile)  
   Se l'indice non è valido (comune non ancora indicizzato) ma il file GML
   esiste ed è ≤ 60 MB, `analyticspro_gml_lookup_streaming()` esegue una
   scansione in streaming con early-exit al primo match. Applicando gli stessi
   fallback foglio e particella descritti sopra.  
   Per file > 60 MB restituisce `null` e logga un avviso: indicizzare il comune
   è sempre preferibile.

Il primo parametro di `analyticspro_gml_lookup()` può essere sia un codice Belfiore (`B394`)
sia un nome comune (`Calcinato`): in quest'ultimo caso il codice viene risolto tramite il
catalogo GML locale.

I job di indicizzazione GML sono gestiti dalle tabelle `gml_index_jobs` / `gml_index_job_log`
(aggiunte dalla migration `005_add_gml_index_jobs.sql`).

### Migration rilevanti

- `004_add_coord_source_to_properties.sql`
- `005_add_gml_index_jobs.sql`
- `006_add_enrichment_report.sql`
- `007_add_enrichment_sync_flag.sql`

### Protezione HTTP

Le directory `storage/gml/` e `storage/gml_index/` contengono un `.htaccess` con
`Require all denied`. Per Nginx, aggiungere nel blocco server:

```nginx
location ~* /storage/gml(_index)?/ {
    deny all;
    return 403;
}
```

### Parser GML streaming

Il file `includes/gml_stream_parser.php` implementa un parser a blocchi (chunk 1 MB)
che:
- Non usa `DOMDocument::load()` (memory-unsafe su file da centinaia di MB).
- Non usa `XMLReader::next($localName)` (bug: confronta il nome qualificato).
- Isola ogni `<X:CadastralParcel>` tramite regex, gestendo l'elemento a cavallo di due chunk.
- Esclude i `posList` interni a `gml:Envelope`/`boundedBy`.
- È indipendente da namespace e prefissi.

### Test

```bash
php analyticspro/tests/gml_stream_smoke.php   # parser streaming + centroide
php analyticspro/tests/gml_parser_smoke.php   # parser DOM esistente
php analyticspro/tests/gml_catalog_smoke.php  # lookup/normalizzazione GML
php analyticspro/tests/gml_resolution_smoke.php
php analyticspro/tests/ade_sql_import_smoke.php
```
