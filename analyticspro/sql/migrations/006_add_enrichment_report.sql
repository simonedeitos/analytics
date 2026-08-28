-- Migration 006: add structured enrichment report to import_batches.
--
-- Written for MySQL 8 compatibility (no MariaDB-only IF NOT EXISTS on ALTER TABLE).
DROP PROCEDURE IF EXISTS _analyticspro_migration_006;
DELIMITER $$
CREATE PROCEDURE _analyticspro_migration_006()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'import_batches'
          AND COLUMN_NAME  = 'enrichment_report'
    ) THEN
        ALTER TABLE import_batches
            ADD COLUMN enrichment_report JSON NULL AFTER enrichment_total;
    END IF;
END$$
DELIMITER ;
CALL _analyticspro_migration_006();
DROP PROCEDURE IF EXISTS _analyticspro_migration_006;
