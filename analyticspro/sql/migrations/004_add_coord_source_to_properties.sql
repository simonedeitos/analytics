-- Migration 004: add `coord_source` column to properties.
-- Tracks which geocoding provider resolved each property's coordinates.
-- Values: 'gml_locale' | 'cache' | 'wfs' | 'zornade' | null
-- Note: 'db_cadastral' è stato rimosso — non implementato; annotato come possibile evoluzione futura.
-- Existing rows with coordinates receive 'wfs' as a conservative default.
--
-- Written for MySQL 8 compatibility (no MariaDB-only IF NOT EXISTS on ALTER TABLE).
DROP PROCEDURE IF EXISTS _analyticspro_migration_004;
DELIMITER $$
CREATE PROCEDURE _analyticspro_migration_004()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'properties'
          AND COLUMN_NAME  = 'coord_source'
    ) THEN
        ALTER TABLE properties
            ADD COLUMN coord_source VARCHAR(20) NULL DEFAULT NULL AFTER posizione_verificata;
    END IF;

    UPDATE properties
    SET coord_source = 'wfs'
    WHERE lat IS NOT NULL
      AND coord_source IS NULL;
END$$
DELIMITER ;
CALL _analyticspro_migration_004();
DROP PROCEDURE IF EXISTS _analyticspro_migration_004;
