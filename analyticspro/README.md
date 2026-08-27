# AnalyticsPRO

Webapp PHP/PDO multi-tenant per importare dati catastali, salvarli su MySQL/MariaDB e visualizzarli su mappa, report e analitiche.

## Setup rapido

1. Copia `analyticspro/.env.example` in `analyticspro/.env` e configura:
   - MySQL/MariaDB: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
   - PostGIS: `POSTGIS_HOST`, `POSTGIS_PORT`, `POSTGIS_DB`, `POSTGIS_USER`, `POSTGIS_PASS`
   - Bootstrap encryption key: `APP_BOOTSTRAP_ENCRYPTION_KEY`
2. Esegui lo schema iniziale MySQL/MariaDB:
   ```bash
   mysql -u USER -p DBNAME < analyticspro/sql/schema.sql
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

## PostGIS / cartografia ADE

AnalyticsPRO prevede un database PostGIS separato per il lookup `foglio + particella -> punto marker`.

### Variabili `.env`

```env
POSTGIS_HOST=127.0.0.1
POSTGIS_PORT=5432
POSTGIS_DB=analytics_gis
POSTGIS_USER=postgres
POSTGIS_PASS=
```

### Tabella attesa nella prima versione

La lookup query usa una tabella `cadastral_parcels` con almeno:

- `provincia_sigla`
- `comune`
- `foglio`
- `particella`
- `geom` (geometry/polygon)

La query marker usa `ST_PointOnSurface(geom)`.

## Worker ADE

- Endpoint admin: `analyticspro/api/admin/ade_jobs.php`
- Worker CLI: `analyticspro/cron/ade_import_worker.php`
- In questa prima PR il worker:
  - salva il job in coda
  - estrae ricorsivamente ZIP annidati
  - aggiorna progress e log in `ade_import_jobs` / `ade_import_job_log`
  - lascia un TODO esplicito per la conversione/import GML -> PostGIS (es. `ogr2ogr` se disponibile)

## Note operative

- Sessioni PHP in tabella `user_sessions`
- Remember me massimo 10 ore
- CSRF su form e API JSON
- Il telefono resta nascosto se `can_view_phone = 0` per il tenant
- I subutenti non possono eliminare dati e, senza `can_edit_all_markers`, possono modificare solo immobili assegnati
