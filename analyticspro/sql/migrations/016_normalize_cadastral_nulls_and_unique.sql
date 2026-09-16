-- Migration 016: normalize nullable cadastral fields and harden the unique constraint.
-- Run analyticspro/tools/merge_duplicate_properties.php --apply before this migration if duplicates exist.
DROP PROCEDURE IF EXISTS _analyticspro_migration_016;
DELIMITER $$
CREATE PROCEDURE _analyticspro_migration_016()
BEGIN
    DECLARE v_duplicate_count BIGINT DEFAULT 0;
    DECLARE v_index_signature TEXT DEFAULT NULL;

    SELECT COUNT(*)
      INTO v_duplicate_count
    FROM (
        SELECT user_id, provincia, comune, COALESCE(cod_catastale, ''), COALESCE(sezione, ''), foglio, particella, COALESCE(subalterno, ''), COUNT(*) AS cnt
        FROM properties
        GROUP BY user_id, provincia, comune, COALESCE(cod_catastale, ''), COALESCE(sezione, ''), foglio, particella, COALESCE(subalterno, '')
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

    IF v_index_signature IS NULL THEN
        ALTER TABLE properties
            ADD UNIQUE KEY uniq_estremi_catastali (user_id, provincia, comune, cod_catastale, sezione, foglio, particella, subalterno);
    ELSEIF v_index_signature <> 'user_id,provincia,comune,cod_catastale,sezione,foglio,particella,subalterno' THEN
        ALTER TABLE properties
            DROP INDEX uniq_estremi_catastali,
            ADD UNIQUE KEY uniq_estremi_catastali (user_id, provincia, comune, cod_catastale, sezione, foglio, particella, subalterno);
    END IF;
END$$
DELIMITER ;
CALL _analyticspro_migration_016();
DROP PROCEDURE IF EXISTS _analyticspro_migration_016;
