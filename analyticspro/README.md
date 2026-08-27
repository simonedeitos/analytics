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

La funzione `analyticspro_lookup_cadastral_coordinates()` in `includes/importer.php`
interroga `cadastral_parcels.interior_point` tramite `ST_Y()` / `ST_X()` per ottenere
`lat` / `lng` da assegnare al marker.

## Worker ADE

- Endpoint admin: `analyticspro/api/admin/ade_jobs.php`
- Worker CLI: `analyticspro/cron/ade_import_worker.php`
- Sezione admin dedicata: `analyticspro/admin/import_ade.php`
- Il worker estrae ricorsivamente ZIP annidati e fa parsing reale dei GML `*_map.gml` / `*_ple.gml`
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
