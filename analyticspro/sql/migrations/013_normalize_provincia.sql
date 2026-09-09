-- Migration 013: normalize properties.provincia to canonical 2-letter sigla.
-- Adds properties.provincia_originale, repairs historical truncated values,
-- handles dedupe collisions safely, and narrows provincia to VARCHAR(2)
-- only when all rows are normalized.
DROP PROCEDURE IF EXISTS _analyticspro_migration_013;
DELIMITER $$
CREATE PROCEDURE _analyticspro_migration_013()
BEGIN
    DECLARE v_has_cadastral_comuni INT DEFAULT 0;
    DECLARE v_has_enrichment_columns INT DEFAULT 0;
    DECLARE v_pending INT DEFAULT 0;
    DECLARE v_col_len INT DEFAULT 0;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'properties'
          AND COLUMN_NAME  = 'provincia_originale'
    ) THEN
        ALTER TABLE properties
            ADD COLUMN provincia_originale VARCHAR(100) NULL AFTER provincia;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'provincia_normalization_audit'
    ) THEN
        CREATE TABLE provincia_normalization_audit (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            run_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            action VARCHAR(40) NOT NULL,
            property_id BIGINT UNSIGNED NULL,
            keeper_id BIGINT UNSIGNED NULL,
            from_value VARCHAR(100) NULL,
            to_value VARCHAR(20) NULL,
            details VARCHAR(255) NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    END IF;

    SELECT COUNT(*) INTO v_has_cadastral_comuni
    FROM INFORMATION_SCHEMA.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'cadastral_comuni';

    SELECT COUNT(*) INTO v_has_enrichment_columns
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'properties'
      AND COLUMN_NAME IN ('coord_source', 'enrichment_attempts', 'enrichment_last_attempt_at', 'enrichment_last_error_code', 'enrichment_last_error_note');

    DROP TEMPORARY TABLE IF EXISTS _ap13_targets;
    CREATE TEMPORARY TABLE _ap13_targets (
        property_id BIGINT UNSIGNED PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        current_value VARCHAR(100) NOT NULL,
        current_key VARCHAR(100) NOT NULL,
        comune_key VARCHAR(150) NOT NULL,
        cod_catastale_key VARCHAR(10) NOT NULL,
        target_sigla VARCHAR(2) NULL,
        resolution_step VARCHAR(30) NULL
    );

    INSERT INTO _ap13_targets (property_id, user_id, current_value, current_key, comune_key, cod_catastale_key)
    SELECT
        p.id,
        p.user_id,
        TRIM(COALESCE(p.provincia, '')),
        UPPER(TRIM(COALESCE(p.provincia, ''))),
        UPPER(TRIM(COALESCE(p.comune, ''))),
        UPPER(TRIM(COALESCE(p.cod_catastale, '')))
    FROM properties p
    WHERE CHAR_LENGTH(TRIM(COALESCE(p.provincia, ''))) <> 2;

    UPDATE properties p
    INNER JOIN _ap13_targets t ON t.property_id = p.id
    SET p.provincia_originale = COALESCE(NULLIF(p.provincia_originale, ''), t.current_value)
    WHERE p.provincia_originale IS NULL OR p.provincia_originale = '';

    IF v_has_cadastral_comuni > 0 THEN
        UPDATE _ap13_targets t
        INNER JOIN cadastral_comuni cc
            ON UPPER(TRIM(cc.cod_catastale)) = t.cod_catastale_key
        SET t.target_sigla = UPPER(TRIM(cc.provincia_sigla)),
            t.resolution_step = 'cod_catastale'
        WHERE t.target_sigla IS NULL
          AND t.cod_catastale_key <> ''
          AND CHAR_LENGTH(TRIM(cc.provincia_sigla)) = 2;

        DROP TEMPORARY TABLE IF EXISTS _ap13_comune_unique;
        CREATE TEMPORARY TABLE _ap13_comune_unique AS
        SELECT
            UPPER(TRIM(nome_comune)) AS comune_key,
            MIN(UPPER(TRIM(provincia_sigla))) AS sigla
        FROM cadastral_comuni
        WHERE TRIM(nome_comune) <> ''
          AND CHAR_LENGTH(TRIM(provincia_sigla)) = 2
        GROUP BY UPPER(TRIM(nome_comune))
        HAVING COUNT(DISTINCT UPPER(TRIM(provincia_sigla))) = 1;

        UPDATE _ap13_targets t
        INNER JOIN _ap13_comune_unique cu ON cu.comune_key = t.comune_key
        SET t.target_sigla = cu.sigla,
            t.resolution_step = 'nome_comune'
        WHERE t.target_sigla IS NULL
          AND t.comune_key <> '';
    END IF;

    DROP TEMPORARY TABLE IF EXISTS _ap13_province_names;
    CREATE TEMPORARY TABLE _ap13_province_names (
        nome_norm VARCHAR(100) NOT NULL,
        sigla VARCHAR(2) NOT NULL,
        PRIMARY KEY (nome_norm)
    );

    INSERT INTO _ap13_province_names (nome_norm, sigla) VALUES
        ('AGRIGENTO','AG'),('ALESSANDRIA','AL'),('ANCONA','AN'),('AOSTA','AO'),('AREZZO','AR'),('ASCOLI PICENO','AP'),('ASTI','AT'),('AVELLINO','AV'),('BARI','BA'),('BARLETTA ANDRIA TRANI','BT'),('BELLUNO','BL'),('BENEVENTO','BN'),('BERGAMO','BG'),('BIELLA','BI'),('BOLOGNA','BO'),('BOLZANO','BZ'),('BRESCIA','BS'),('BRINDISI','BR'),('CAGLIARI','CA'),('CALTANISSETTA','CL'),('CAMPOBASSO','CB'),('CASERTA','CE'),('CATANIA','CT'),('CATANZARO','CZ'),('CHIETI','CH'),('COMO','CO'),('COSENZA','CS'),('CREMONA','CR'),('CROTONE','KR'),('CUNEO','CN'),('ENNA','EN'),('FERMO','FM'),('FERRARA','FE'),('FIRENZE','FI'),('FOGGIA','FG'),('FORLI CESENA','FC'),('FROSINONE','FR'),('GENOVA','GE'),('GORIZIA','GO'),('GROSSETO','GR'),('IMPERIA','IM'),('ISERNIA','IS'),('LA SPEZIA','SP'),('LAQUILA','AQ'),('LATINA','LT'),('LECCE','LE'),('LECCO','LC'),('LIVORNO','LI'),('LODI','LO'),('LUCCA','LU'),('MACERATA','MC'),('MANTOVA','MN'),('MASSA CARRARA','MS'),('MATERA','MT'),('MESSINA','ME'),('MILANO','MI'),('MODENA','MO'),('MONZA E BRIANZA','MB'),('MONZA E DELLA BRIANZA','MB'),('NAPOLI','NA'),('NOVARA','NO'),('NUORO','NU'),('ORISTANO','OR'),('PADOVA','PD'),('PALERMO','PA'),('PARMA','PR'),('PAVIA','PV'),('PERUGIA','PG'),('PESARO E URBINO','PU'),('PESCARA','PE'),('PIACENZA','PC'),('PISA','PI'),('PISTOIA','PT'),('PORDENONE','PN'),('POTENZA','PZ'),('PRATO','PO'),('RAGUSA','RG'),('RAVENNA','RA'),('REGGIO CALABRIA','RC'),('REGGIO DI CALABRIA','RC'),('REGGIO EMILIA','RE'),('RIETI','RI'),('RIMINI','RN'),('ROMA','RM'),('ROVIGO','RO'),('SALERNO','SA'),('SASSARI','SS'),('SAVONA','SV'),('SIENA','SI'),('SIRACUSA','SR'),('SONDRIO','SO'),('SUD SARDEGNA','SU'),('TARANTO','TA'),('TERAMO','TE'),('TERNI','TR'),('TORINO','TO'),('TRAPANI','TP'),('TRENTO','TN'),('TREVISO','TV'),('TRIESTE','TS'),('UDINE','UD'),('VARESE','VA'),('VENEZIA','VE'),('VERBANO CUSIO OSSOLA','VB'),('VERCELLI','VC'),('VERONA','VR'),('VIBO VALENTIA','VV'),('VICENZA','VI'),('VITERBO','VT'),('VALLE DAOSTA','AO'),('VALLE D AOSTA','AO'),('SUDTIROL','BZ');

    DROP TEMPORARY TABLE IF EXISTS _ap13_prefix_match;
    CREATE TEMPORARY TABLE _ap13_prefix_match AS
    SELECT
        t.property_id,
        MIN(pn.sigla) AS sigla,
        COUNT(DISTINCT pn.sigla) AS match_count
    FROM _ap13_targets t
    INNER JOIN _ap13_province_names pn
        ON REPLACE(pn.nome_norm, ' ', '') LIKE CONCAT(REPLACE(t.current_key, ' ', ''), '%')
    WHERE t.target_sigla IS NULL
      AND t.current_key <> ''
    GROUP BY t.property_id;

    UPDATE _ap13_targets t
    INNER JOIN _ap13_prefix_match pm ON pm.property_id = t.property_id
    SET t.target_sigla = pm.sigla,
        t.resolution_step = 'prefix_univoco'
    WHERE t.target_sigla IS NULL
      AND pm.match_count = 1;

    DROP TEMPORARY TABLE IF EXISTS _ap13_updates;
    CREATE TEMPORARY TABLE _ap13_updates AS
    SELECT
        property_id,
        current_value,
        current_key,
        target_sigla,
        resolution_step
    FROM _ap13_targets
    WHERE target_sigla IS NOT NULL
      AND target_sigla <> current_key;

    DROP TEMPORARY TABLE IF EXISTS _ap13_ranked;
    CREATE TEMPORARY TABLE _ap13_ranked AS
    SELECT
        p.id,
        p.user_id,
        COALESCE(u.target_sigla, UPPER(TRIM(p.provincia))) AS final_provincia,
        UPPER(TRIM(p.comune)) AS comune_key,
        COALESCE(UPPER(TRIM(p.sezione)), '') AS sezione_key,
        UPPER(TRIM(p.foglio)) AS foglio_key,
        UPPER(TRIM(p.particella)) AS particella_key,
        COALESCE(UPPER(TRIM(p.subalterno)), '') AS subalterno_key,
        ROW_NUMBER() OVER (
            PARTITION BY p.user_id,
                COALESCE(u.target_sigla, UPPER(TRIM(p.provincia))),
                UPPER(TRIM(p.comune)),
                COALESCE(UPPER(TRIM(p.sezione)), ''),
                UPPER(TRIM(p.foglio)),
                UPPER(TRIM(p.particella)),
                COALESCE(UPPER(TRIM(p.subalterno)), '')
            ORDER BY CASE WHEN p.lat IS NOT NULL AND p.lng IS NOT NULL THEN 1 ELSE 0 END DESC, p.id ASC
        ) AS rn
    FROM properties p
    LEFT JOIN _ap13_updates u ON u.property_id = p.id;

    DROP TEMPORARY TABLE IF EXISTS _ap13_merge_map;
    CREATE TEMPORARY TABLE _ap13_merge_map AS
    SELECT
        loser.id AS loser_id,
        keeper.id AS keeper_id
    FROM _ap13_ranked loser
    INNER JOIN _ap13_ranked keeper
        ON keeper.user_id = loser.user_id
       AND keeper.final_provincia = loser.final_provincia
       AND keeper.comune_key = loser.comune_key
       AND keeper.sezione_key = loser.sezione_key
       AND keeper.foglio_key = loser.foglio_key
       AND keeper.particella_key = loser.particella_key
       AND keeper.subalterno_key = loser.subalterno_key
       AND keeper.rn = 1
    WHERE loser.rn > 1
      AND (
          EXISTS (SELECT 1 FROM _ap13_updates u1 WHERE u1.property_id = loser.id)
          OR EXISTS (SELECT 1 FROM _ap13_updates u2 WHERE u2.property_id = keeper.id)
      );

    INSERT INTO provincia_normalization_audit (action, property_id, keeper_id, from_value, to_value, details)
    SELECT
        'merge',
        mm.loser_id,
        mm.keeper_id,
        p.provincia,
        k.provincia,
        'Collisione su uniq_estremi_catastali: mantenuta riga preferita (coordinate valorizzate o id minore)'
    FROM _ap13_merge_map mm
    INNER JOIN properties p ON p.id = mm.loser_id
    INNER JOIN properties k ON k.id = mm.keeper_id;

    INSERT IGNORE INTO property_assignments (property_id, subuser_id, assigned_by, assigned_at)
    SELECT mm.keeper_id, pa.subuser_id, pa.assigned_by, pa.assigned_at
    FROM property_assignments pa
    INNER JOIN _ap13_merge_map mm ON mm.loser_id = pa.property_id;

    DROP TEMPORARY TABLE IF EXISTS _ap13_keeper_has_current;
    CREATE TEMPORARY TABLE _ap13_keeper_has_current AS
    SELECT DISTINCT po.property_id AS keeper_id
    FROM property_owners po
    INNER JOIN (SELECT DISTINCT keeper_id FROM _ap13_merge_map) mk ON mk.keeper_id = po.property_id
    WHERE po.is_current = 1;

    UPDATE property_owners po
    INNER JOIN _ap13_merge_map mm ON mm.loser_id = po.property_id
    INNER JOIN _ap13_keeper_has_current kc ON kc.keeper_id = mm.keeper_id
    SET po.is_current = 0,
        po.valid_to = COALESCE(po.valid_to, NOW())
    WHERE po.is_current = 1;

    UPDATE property_owners po
    INNER JOIN _ap13_merge_map mm ON mm.loser_id = po.property_id
    SET po.property_id = mm.keeper_id;

    UPDATE property_notes pn
    INNER JOIN _ap13_merge_map mm ON mm.loser_id = pn.property_id
    SET pn.property_id = mm.keeper_id;

    UPDATE property_status_history psh
    INNER JOIN _ap13_merge_map mm ON mm.loser_id = psh.property_id
    SET psh.property_id = mm.keeper_id;

    UPDATE import_duplicate_conflicts idc
    INNER JOIN _ap13_merge_map mm ON mm.loser_id = idc.property_id
    SET idc.property_id = mm.keeper_id;

    DELETE p
    FROM properties p
    INNER JOIN _ap13_merge_map mm ON mm.loser_id = p.id;

    UPDATE properties p
    INNER JOIN _ap13_updates u ON u.property_id = p.id
    SET p.provincia = u.target_sigla,
        p.provincia_originale = COALESCE(NULLIF(p.provincia_originale, ''), u.current_value);

    IF v_has_enrichment_columns = 5 THEN
        UPDATE properties p
        INNER JOIN _ap13_updates u ON u.property_id = p.id
        SET p.enrichment_attempts = 0,
            p.enrichment_last_attempt_at = NULL,
            p.enrichment_last_error_code = NULL,
            p.enrichment_last_error_note = NULL,
            p.coord_source = NULL
        WHERE p.coord_source = 'unresolved';
    END IF;

    INSERT INTO provincia_normalization_audit (action, property_id, from_value, details)
    SELECT
        'unresolved',
        t.property_id,
        t.current_value,
        'Nessuna normalizzazione automatica disponibile (codice/comune/prefisso non univoci)'
    FROM _ap13_targets t
    WHERE t.target_sigla IS NULL;

    INSERT INTO provincia_normalization_audit (action, details)
    SELECT
        'summary',
        CONCAT(
            'Normalizzate=', (SELECT COUNT(*) FROM _ap13_updates),
            ', fusioni=', (SELECT COUNT(*) FROM _ap13_merge_map),
            ', non_riparate=', (SELECT COUNT(*) FROM _ap13_targets WHERE target_sigla IS NULL)
        );

    SELECT COUNT(*) INTO v_pending
    FROM properties
    WHERE CHAR_LENGTH(TRIM(COALESCE(provincia, ''))) <> 2;

    SELECT COALESCE(CHARACTER_MAXIMUM_LENGTH, 0) INTO v_col_len
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'properties'
      AND COLUMN_NAME = 'provincia'
    LIMIT 1;

    IF v_pending = 0 AND v_col_len <> 2 THEN
        ALTER TABLE properties
            MODIFY COLUMN provincia VARCHAR(2) NOT NULL;
    ELSE
        INSERT INTO provincia_normalization_audit (action, details)
        VALUES ('warning', CONCAT('VARCHAR(2) non applicato: restano ', v_pending, ' righe con provincia non normalizzata.'));
    END IF;
END$$
DELIMITER ;
CALL _analyticspro_migration_013();
DROP PROCEDURE IF EXISTS _analyticspro_migration_013;
