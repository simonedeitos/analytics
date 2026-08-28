-- Migration 005: tabelle per i job di indicizzazione GML in background.
-- Stesso pattern di ade_import_jobs / ade_import_job_log.

CREATE TABLE IF NOT EXISTS gml_index_jobs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    belfiore VARCHAR(4) NOT NULL COMMENT 'Codice Belfiore o "ALL" per tutti i comuni',
    status ENUM('queued','running','completed','failed') NOT NULL DEFAULT 'queued',
    total_comuni INT UNSIGNED DEFAULT 0,
    processed_comuni INT UNSIGNED DEFAULT 0,
    total_particelle BIGINT UNSIGNED DEFAULT 0,
    processed_particelle BIGINT UNSIGNED DEFAULT 0,
    started_at DATETIME NULL,
    completed_at DATETIME NULL,
    error_message TEXT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS gml_index_job_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id INT UNSIGNED NOT NULL,
    level ENUM('info','warning','error') NOT NULL DEFAULT 'info',
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (job_id) REFERENCES gml_index_jobs(id) ON DELETE CASCADE,
    INDEX idx_job_id_id (job_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
