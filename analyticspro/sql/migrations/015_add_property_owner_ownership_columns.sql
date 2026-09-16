-- Migration 015: add per-owner quota/titolarita and backfill from the parent property.
DROP PROCEDURE IF EXISTS _analyticspro_migration_015;
DELIMITER $$
CREATE PROCEDURE _analyticspro_migration_015()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'property_owners'
          AND COLUMN_NAME = 'quota'
    ) THEN
        ALTER TABLE property_owners
            ADD COLUMN quota VARCHAR(50) NULL AFTER genere;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'property_owners'
          AND COLUMN_NAME = 'titolarita'
    ) THEN
        ALTER TABLE property_owners
            ADD COLUMN titolarita VARCHAR(100) NULL AFTER quota;
    END IF;

    UPDATE property_owners po
    INNER JOIN properties p ON p.id = po.property_id
    SET po.quota = COALESCE(NULLIF(po.quota, ''), p.quota),
        po.titolarita = COALESCE(NULLIF(po.titolarita, ''), p.titolarita)
    WHERE (po.quota IS NULL OR po.quota = '' OR po.titolarita IS NULL OR po.titolarita = '');
END$$
DELIMITER ;
CALL _analyticspro_migration_015();
DROP PROCEDURE IF EXISTS _analyticspro_migration_015;
