-- Migration 014: ensure property_owners.valid_to exists for owner historization.
DROP PROCEDURE IF EXISTS _analyticspro_migration_014;
DELIMITER $$
CREATE PROCEDURE _analyticspro_migration_014()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'property_owners'
          AND COLUMN_NAME = 'valid_to'
    ) THEN
        ALTER TABLE property_owners
            ADD COLUMN valid_to DATETIME NULL AFTER valid_from;
    END IF;
END$$
DELIMITER ;
CALL _analyticspro_migration_014();
DROP PROCEDURE IF EXISTS _analyticspro_migration_014;
