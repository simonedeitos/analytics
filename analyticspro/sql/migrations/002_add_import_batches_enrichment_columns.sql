-- Migration 002: add enrichment progress columns to import_batches.
-- These columns track the async WFS coordinate enrichment phase that runs
-- after the main import persistence is complete.
--
-- Written for MySQL 8 compatibility (no MariaDB-only IF NOT EXISTS on ALTER TABLE).
DROP PROCEDURE IF EXISTS _analyticspro_migration_002;
DELIMITER $$
CREATE PROCEDURE _analyticspro_migration_002()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'import_batches'
          AND COLUMN_NAME  = 'enrichment_status'
    ) THEN
        ALTER TABLE import_batches
            ADD COLUMN enrichment_status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending' AFTER completed_at;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'import_batches'
          AND COLUMN_NAME  = 'enrichment_processed'
    ) THEN
        ALTER TABLE import_batches
            ADD COLUMN enrichment_processed INT UNSIGNED NOT NULL DEFAULT 0 AFTER enrichment_status;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'import_batches'
          AND COLUMN_NAME  = 'enrichment_total'
    ) THEN
        ALTER TABLE import_batches
            ADD COLUMN enrichment_total INT UNSIGNED NOT NULL DEFAULT 0 AFTER enrichment_processed;
    END IF;
END$$
DELIMITER ;
CALL _analyticspro_migration_002();
DROP PROCEDURE IF EXISTS _analyticspro_migration_002;
