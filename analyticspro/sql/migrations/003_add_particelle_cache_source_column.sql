-- Migration 003: add `source` column to particelle_cache.
-- Tracks which geocoding provider (Zornade or WFS-AdE) resolved each parcel.
-- Existing rows receive the default value 'WFS-AdE' to preserve backwards compatibility.
--
-- Written for MySQL 8 compatibility (no MariaDB-only IF NOT EXISTS on ALTER TABLE).
DROP PROCEDURE IF EXISTS _analyticspro_migration_003;
DELIMITER $$
CREATE PROCEDURE _analyticspro_migration_003()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'particelle_cache'
          AND COLUMN_NAME  = 'source'
    ) THEN
        ALTER TABLE particelle_cache
            ADD COLUMN source VARCHAR(20) DEFAULT 'WFS-AdE';
    END IF;
END$$
DELIMITER ;
CALL _analyticspro_migration_003();
DROP PROCEDURE IF EXISTS _analyticspro_migration_003;
