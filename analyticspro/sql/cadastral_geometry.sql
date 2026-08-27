-- ============================================================
-- AnalyticsPRO – Cadastral geometry tables (MySQL/MariaDB)
-- Replaces the previously-planned PostGIS external database.
-- All spatial data is stored in the same MySQL applicative DB.
-- Requires MySQL 8.0+ or MariaDB 10.5+ for GEOMETRY/SPATIAL support.
-- ============================================================

-- Comuni importati dall'ADE (una riga per comune/provincia)
CREATE TABLE IF NOT EXISTS cadastral_comuni (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provincia_sigla  VARCHAR(4)   NOT NULL,
    cod_catastale    VARCHAR(10)  NOT NULL,
    nome_comune      VARCHAR(150) NOT NULL,
    ade_import_job_id INT UNSIGNED NULL,
    map_gml_filename VARCHAR(255) NULL,
    ple_gml_filename VARCHAR(255) NULL,
    imported_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_cod_catastale (cod_catastale),
    INDEX idx_provincia (provincia_sigla),
    FOREIGN KEY (ade_import_job_id) REFERENCES ade_import_jobs(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Particelle catastali con geometria (SRID 4326 = WGS84)
CREATE TABLE IF NOT EXISTS cadastral_parcels (
    id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    comune_id       INT UNSIGNED NOT NULL,
    cod_catastale   VARCHAR(10)  NOT NULL,
    sezione         VARCHAR(10)  NULL,
    foglio          VARCHAR(20)  NOT NULL,
    particella      VARCHAR(20)  NOT NULL,
    -- GEOMETRY stored as WKB with SRID 4326
    geom            GEOMETRY     NOT NULL /*!80003 SRID 4326 */,
    -- Interior/representative point used to place the marker on the map.
    -- Populated during import: ST_PointOnSurface(geom) if MySQL 8.0.30+,
    -- otherwise ST_Centroid(geom) with ST_Contains verification; if centroid
    -- is outside the polygon a PHP fallback computes the bounding-box centre.
    -- TODO: refine with ST_PointOnSurface once MySQL 8.0.30+ is confirmed.
    interior_point  POINT        NOT NULL /*!80003 SRID 4326 */,
    area_mq         DECIMAL(14,2) NULL,
    source_file     VARCHAR(255) NULL,
    imported_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (comune_id) REFERENCES cadastral_comuni(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_parcel (comune_id, sezione, foglio, particella),
    SPATIAL INDEX idx_geom (geom),
    SPATIAL INDEX idx_interior_point (interior_point),
    INDEX idx_lookup (cod_catastale, foglio, particella)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabella di verifica delle posizioni dei marker
CREATE TABLE IF NOT EXISTS cadastral_parcel_verification (
    id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parcel_id        BIGINT UNSIGNED NOT NULL,
    metodo           ENUM('interior_point','fabbricato','grid_search') NOT NULL DEFAULT 'interior_point',
    verificato       TINYINT(1)  NOT NULL DEFAULT 0,
    tentativi        INT UNSIGNED NOT NULL DEFAULT 0,
    ultima_risposta  JSON NULL,
    aggiornato_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parcel_id) REFERENCES cadastral_parcels(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_parcel_verification (parcel_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
