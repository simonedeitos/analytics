-- Migration 017: align properties.provincia_originale charset/collation with properties.provincia.
DROP PROCEDURE IF EXISTS _analyticspro_migration_017;
DELIMITER $$
CREATE PROCEDURE _analyticspro_migration_017()
BEGIN
    migration: BEGIN
    DECLARE v_has_properties INT DEFAULT 0;
    DECLARE v_has_provincia INT DEFAULT 0;
    DECLARE v_has_provincia_originale INT DEFAULT 0;
    DECLARE v_target_charset VARCHAR(64) DEFAULT NULL;
    DECLARE v_target_collation VARCHAR(64) DEFAULT NULL;
    DECLARE v_current_charset VARCHAR(64) DEFAULT NULL;
    DECLARE v_current_collation VARCHAR(64) DEFAULT NULL;
    DECLARE v_table_collation VARCHAR(64) DEFAULT NULL;
    DECLARE v_sql TEXT DEFAULT NULL;

    SELECT COUNT(*) INTO v_has_properties
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'properties';

    IF v_has_properties = 0 THEN
        LEAVE migration;
    END IF;

    SELECT COUNT(*) INTO v_has_provincia
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'properties'
      AND COLUMN_NAME = 'provincia';

    IF v_has_provincia = 0 THEN
        LEAVE migration;
    END IF;

    SELECT CHARACTER_SET_NAME, COLLATION_NAME
    INTO v_target_charset, v_target_collation
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'properties'
      AND COLUMN_NAME = 'provincia'
    LIMIT 1;

    IF v_target_charset IS NULL OR v_target_collation IS NULL THEN
        SELECT TABLE_COLLATION INTO v_table_collation
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'properties'
        LIMIT 1;

        IF v_table_collation IS NOT NULL THEN
            SELECT CHARACTER_SET_NAME
            INTO v_target_charset
            FROM INFORMATION_SCHEMA.COLLATIONS
            WHERE COLLATION_NAME = v_table_collation
            LIMIT 1;

            SET v_target_collation = v_table_collation;
        END IF;
    END IF;

    IF v_target_charset IS NULL OR v_target_collation IS NULL THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Impossibile determinare charset/collation canonici per properties.provincia.';
    END IF;

    SELECT COUNT(*) INTO v_has_provincia_originale
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'properties'
      AND COLUMN_NAME = 'provincia_originale';

    IF v_has_provincia_originale = 0 THEN
        SET v_sql = CONCAT(
            'ALTER TABLE properties ',
            'ADD COLUMN provincia_originale VARCHAR(100) CHARACTER SET ', v_target_charset,
            ' COLLATE ', v_target_collation,
            ' NULL AFTER provincia'
        );
        PREPARE stmt FROM v_sql;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    ELSE
        SELECT CHARACTER_SET_NAME, COLLATION_NAME
        INTO v_current_charset, v_current_collation
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'properties'
          AND COLUMN_NAME = 'provincia_originale'
        LIMIT 1;

        IF COALESCE(v_current_charset, '') <> v_target_charset
           OR COALESCE(v_current_collation, '') <> v_target_collation THEN
            SET v_sql = CONCAT(
                'ALTER TABLE properties ',
                'MODIFY COLUMN provincia_originale VARCHAR(100) CHARACTER SET ', v_target_charset,
                ' COLLATE ', v_target_collation,
                ' NULL'
            );
            PREPARE stmt FROM v_sql;
            EXECUTE stmt;
            DEALLOCATE PREPARE stmt;
        END IF;
    END IF;
    END migration;
END$$
DELIMITER ;
CALL _analyticspro_migration_017();
DROP PROCEDURE IF EXISTS _analyticspro_migration_017;
