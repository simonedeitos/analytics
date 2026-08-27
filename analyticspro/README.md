# AnalyticsPRO

Webapp PHP/PDO multi-tenant per importare dati catastali, salvarli su MySQL/MariaDB e visualizzarli su mappa, report e analitiche.

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

**Fase 1 — Persistenza dati (sempre garantita)**  
Il worker `cron/process_import_batch.php` (o il fallback sincrono) scrive **tutte** le righe del
file in `properties` con `lat = NULL`, `lng = NULL`, `posizione_verificata = 0`,
senza effettuare alcuna chiamata di rete. Tutti i dati catastali vengono sempre salvati
indipendentemente dalla disponibilità del servizio WFS. Al completamento della fase 1
l'interfaccia mostra "Import completato" e il polling si sblocca.

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

Se `proc_open`/`shell_exec` non sono disponibili (hosting limitati), il fallback sincrono in
`cron/process_import_batch.php` esegue l'arricchimento nello stesso processo subito dopo la
Fase 1. In ogni caso la Fase 1 è sempre atomicamente completata prima di qualsiasi chiamata WFS.

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
