-- Migration 012: add enrichment attempt tracking columns to properties.
-- MySQL 8-compatible and idempotent.
DROP PROCEDURE IF EXISTS _analyticspro_migration_012;
DELIMITER $$
CREATE PROCEDURE _analyticspro_migration_012()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'properties'
          AND COLUMN_NAME  = 'enrichment_attempts'
    ) THEN
        ALTER TABLE properties
            ADD COLUMN enrichment_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0 AFTER coord_source;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'properties'
          AND COLUMN_NAME  = 'enrichment_last_attempt_at'
    ) THEN
        ALTER TABLE properties
            ADD COLUMN enrichment_last_attempt_at DATETIME NULL AFTER enrichment_attempts;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'properties'
          AND COLUMN_NAME  = 'enrichment_last_error_code'
    ) THEN
        ALTER TABLE properties
            ADD COLUMN enrichment_last_error_code VARCHAR(64) NULL AFTER enrichment_last_attempt_at;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'properties'
          AND COLUMN_NAME  = 'enrichment_last_error_note'
    ) THEN
        ALTER TABLE properties
            ADD COLUMN enrichment_last_error_note VARCHAR(255) NULL AFTER enrichment_last_error_code;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'properties'
          AND INDEX_NAME = 'idx_import_enrich_attempts'
    ) THEN
        CREATE INDEX idx_import_enrich_attempts
            ON properties (import_batch_id, lat, enrichment_attempts, coord_source);
    END IF;
END$$
DELIMITER ;
CALL _analyticspro_migration_012();
DROP PROCEDURE IF EXISTS _analyticspro_migration_012;
