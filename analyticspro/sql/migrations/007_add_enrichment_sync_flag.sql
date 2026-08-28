-- Migration 007: add enrichment_sync flag to import_batches.
-- Set to 1 when the background enrichment worker cannot be launched
-- (proc_open/shell_exec disabled on shared hosting), enabling the
-- polling-based synchronous chunk fallback via api/data/enrich_chunk.php.
--
-- Written for MySQL 8 compatibility (no MariaDB-only IF NOT EXISTS on ALTER TABLE).
DROP PROCEDURE IF EXISTS _analyticspro_migration_007;
DELIMITER $$
CREATE PROCEDURE _analyticspro_migration_007()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'import_batches'
          AND COLUMN_NAME  = 'enrichment_sync'
    ) THEN
        ALTER TABLE import_batches
            ADD COLUMN enrichment_sync TINYINT(1) NOT NULL DEFAULT 0 AFTER enrichment_report;
    END IF;
END$$
DELIMITER ;
CALL _analyticspro_migration_007();
DROP PROCEDURE IF EXISTS _analyticspro_migration_007;
