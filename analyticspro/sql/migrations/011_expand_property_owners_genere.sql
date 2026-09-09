-- Migration 011: widen property_owners.genere to support values like "Società".
DROP PROCEDURE IF EXISTS _analyticspro_migration_011;
DELIMITER $$
CREATE PROCEDURE _analyticspro_migration_011()
BEGIN
    IF EXISTS (
        SELECT 1
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'property_owners'
          AND COLUMN_NAME = 'genere'
          AND (DATA_TYPE <> 'varchar' OR COALESCE(CHARACTER_MAXIMUM_LENGTH, 0) < 16)
    ) THEN
        ALTER TABLE property_owners
            MODIFY COLUMN genere VARCHAR(16) NULL;
    END IF;
END$$
DELIMITER ;
CALL _analyticspro_migration_011();
DROP PROCEDURE IF EXISTS _analyticspro_migration_011;
