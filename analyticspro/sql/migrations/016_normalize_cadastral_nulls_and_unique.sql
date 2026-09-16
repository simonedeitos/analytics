-- Migration 016: normalize nullable cadastral fields and harden the unique constraint.
-- Run analyticspro/tools/merge_duplicate_properties.php --apply before this migration if duplicates exist.
DROP PROCEDURE IF EXISTS _analyticspro_migration_016;
DELIMITER $$
CREATE PROCEDURE _analyticspro_migration_016()
BEGIN
    DECLARE v_duplicate_count BIGINT DEFAULT 0;
    DECLARE v_index_signature TEXT DEFAULT NULL;
    DECLARE v_index_non_unique INT DEFAULT NULL;

    SELECT COUNT(*)
      INTO v_duplicate_count
    FROM (
        SELECT
            user_id,
            UPPER(TRIM(provincia)) AS provincia_key,
            REGEXP_REPLACE(UPPER(TRIM(comune)), '[^A-Z0-9/]+', ' ') AS comune_key,
            UPPER(TRIM(COALESCE(cod_catastale, ''))) AS cod_catastale_key,
            UPPER(TRIM(COALESCE(sezione, ''))) AS sezione_key,
            CASE
                WHEN UPPER(TRIM(foglio)) REGEXP '^[0-9]+' THEN CONCAT(
                    COALESCE(NULLIF(TRIM(LEADING '0' FROM REGEXP_SUBSTR(UPPER(TRIM(foglio)), '^[0-9]+')), ''), '0'),
                    REGEXP_REPLACE(UPPER(TRIM(foglio)), '^[0-9]+', '')
                )
                ELSE UPPER(TRIM(foglio))
            END AS foglio_key,
            CASE
                WHEN UPPER(TRIM(particella)) REGEXP '^[0-9]+' THEN CONCAT(
                    COALESCE(NULLIF(TRIM(LEADING '0' FROM REGEXP_SUBSTR(UPPER(TRIM(particella)), '^[0-9]+')), ''), '0'),
                    REGEXP_REPLACE(UPPER(TRIM(particella)), '^[0-9]+', '')
                )
                ELSE UPPER(TRIM(particella))
            END AS particella_key,
            CASE
                WHEN UPPER(TRIM(COALESCE(subalterno, ''))) REGEXP '^[0-9]+' THEN CONCAT(
                    COALESCE(NULLIF(TRIM(LEADING '0' FROM REGEXP_SUBSTR(UPPER(TRIM(COALESCE(subalterno, ''))), '^[0-9]+')), ''), '0'),
                    REGEXP_REPLACE(UPPER(TRIM(COALESCE(subalterno, ''))), '^[0-9]+', '')
                )
                ELSE UPPER(TRIM(COALESCE(subalterno, '')))
            END AS subalterno_key,
            COUNT(*) AS cnt
        FROM properties
        GROUP BY user_id, provincia_key, comune_key, cod_catastale_key, sezione_key, foglio_key, particella_key, subalterno_key
        HAVING COUNT(*) > 1
    ) dup;

    IF v_duplicate_count > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migration 016 blocked: run analyticspro/tools/merge_duplicate_properties.php --apply before normalizing cadastral NULL fields.';
    END IF;

    UPDATE properties
    SET cod_catastale = COALESCE(cod_catastale, ''),
        sezione = COALESCE(sezione, ''),
        subalterno = COALESCE(subalterno, '')
    WHERE cod_catastale IS NULL OR sezione IS NULL OR subalterno IS NULL;

    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'properties'
          AND COLUMN_NAME = 'cod_catastale'
          AND IS_NULLABLE = 'YES'
    ) THEN
        ALTER TABLE properties
            MODIFY COLUMN cod_catastale VARCHAR(10) NOT NULL DEFAULT '';
    END IF;

    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'properties'
          AND COLUMN_NAME = 'sezione'
          AND IS_NULLABLE = 'YES'
    ) THEN
        ALTER TABLE properties
            MODIFY COLUMN sezione VARCHAR(10) NOT NULL DEFAULT '';
    END IF;

    IF EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'properties'
          AND COLUMN_NAME = 'subalterno'
          AND IS_NULLABLE = 'YES'
    ) THEN
        ALTER TABLE properties
            MODIFY COLUMN subalterno VARCHAR(20) NOT NULL DEFAULT '';
    END IF;

    SELECT GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',')
      INTO v_index_signature
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'properties'
      AND INDEX_NAME = 'uniq_estremi_catastali';

    SELECT MAX(NON_UNIQUE)
      INTO v_index_non_unique
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'properties'
      AND INDEX_NAME = 'uniq_estremi_catastali';

    IF v_index_signature IS NULL THEN
        ALTER TABLE properties
            ADD UNIQUE KEY uniq_estremi_catastali (user_id, provincia, comune, cod_catastale, sezione, foglio, particella, subalterno);
    ELSEIF v_index_signature <> 'user_id,provincia,comune,cod_catastale,sezione,foglio,particella,subalterno'
        OR COALESCE(v_index_non_unique, 1) <> 0 THEN
        ALTER TABLE properties
            DROP INDEX uniq_estremi_catastali,
            ADD UNIQUE KEY uniq_estremi_catastali (user_id, provincia, comune, cod_catastale, sezione, foglio, particella, subalterno);
    END IF;
END$$
DELIMITER ;
CALL _analyticspro_migration_016();
DROP PROCEDURE IF EXISTS _analyticspro_migration_016;
