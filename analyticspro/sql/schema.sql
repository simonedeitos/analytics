CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_user_id INT UNSIGNED NULL,
    role ENUM('admin','user','subuser') NOT NULL DEFAULT 'user',
    nome VARCHAR(100) NOT NULL,
    cognome VARCHAR(100) NOT NULL,
    email VARCHAR(190) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('pending','active','disabled') NOT NULL DEFAULT 'pending',
    can_view_phone TINYINT(1) NOT NULL DEFAULT 0,
    remember_token VARCHAR(255) NULL,
    remember_token_expires_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    approved_at DATETIME NULL,
    approved_by INT UNSIGNED NULL,
    FOREIGN KEY (parent_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_parent (parent_user_id),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE subuser_permissions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subuser_id INT UNSIGNED NOT NULL UNIQUE,
    can_edit_all_markers TINYINT(1) NOT NULL DEFAULT 0,
    can_import TINYINT(1) NOT NULL DEFAULT 0,
    can_view_analytics TINYINT(1) NOT NULL DEFAULT 0,
    can_view_reports TINYINT(1) NOT NULL DEFAULT 0,
    can_export TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (subuser_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE system_config (
    `key` VARCHAR(100) PRIMARY KEY,
    `value` TEXT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE import_batches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    uploaded_by INT UNSIGNED NOT NULL,
    filename VARCHAR(255) NOT NULL,
    total_rows INT UNSIGNED DEFAULT 0,
    processed_rows INT UNSIGNED DEFAULT 0,
    status ENUM('processing','completed','failed') NOT NULL DEFAULT 'processing',
    error_message TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    enrichment_status ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending',
    enrichment_processed INT UNSIGNED NOT NULL DEFAULT 0,
    enrichment_total INT UNSIGNED NOT NULL DEFAULT 0,
    enrichment_report JSON NULL,
    enrichment_sync TINYINT(1) NOT NULL DEFAULT 0,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (uploaded_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE properties (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    import_batch_id INT UNSIGNED NULL,
    provincia VARCHAR(4) NOT NULL,
    comune VARCHAR(100) NOT NULL,
    cod_catastale VARCHAR(10) NULL,
    sezione VARCHAR(10) NULL,
    foglio VARCHAR(20) NOT NULL,
    particella VARCHAR(20) NOT NULL,
    subalterno VARCHAR(20) NULL,
    indirizzo VARCHAR(255) NULL,
    civico VARCHAR(20) NULL,
    categoria VARCHAR(50) NULL,
    classe VARCHAR(50) NULL,
    consistenza VARCHAR(50) NULL,
    superficie VARCHAR(50) NULL,
    rendita VARCHAR(50) NULL,
    titolarita VARCHAR(100) NULL,
    quota VARCHAR(50) NULL,
    lat DECIMAL(10,7) NULL,
    lng DECIMAL(10,7) NULL,
    posizione_verificata TINYINT(1) NOT NULL DEFAULT 0,
    coord_source VARCHAR(20) NULL,
    stato ENUM('non_interessato','interessato','contattato','da_contattare','in_vendita_noi','in_vendita_altri','altro') NOT NULL DEFAULT 'da_contattare',
    stato_personalizzato VARCHAR(100) NULL,
    colore_marker VARCHAR(7) NOT NULL DEFAULT '#0d6efd',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (import_batch_id) REFERENCES import_batches(id) ON DELETE SET NULL,
    UNIQUE KEY uniq_estremi_catastali (user_id, provincia, comune, sezione, foglio, particella, subalterno),
    INDEX idx_user (user_id),
    INDEX idx_stato (stato),
    INDEX idx_coords (lat, lng)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE property_owners (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    property_id BIGINT UNSIGNED NOT NULL,
    tipo ENUM('persona','azienda') NOT NULL DEFAULT 'persona',
    nome_enc VARBINARY(512) NULL,
    cognome_enc VARBINARY(512) NULL,
    codice_fiscale_enc VARBINARY(512) NULL,
    telefono_enc VARBINARY(512) NULL,
    indirizzo_enc VARBINARY(512) NULL,
    email_enc VARBINARY(512) NULL,
    nome_hash CHAR(64) NULL,
    cognome_hash CHAR(64) NULL,
    codice_fiscale_hash CHAR(64) NULL,
    telefono_hash CHAR(64) NULL,
    data_nascita DATE NULL,
    genere CHAR(1) NULL,
    is_current TINYINT(1) NOT NULL DEFAULT 1,
    valid_from DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    valid_to DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    INDEX idx_property (property_id, is_current),
    INDEX idx_cf_hash (codice_fiscale_hash),
    INDEX idx_cognome_hash (cognome_hash),
    INDEX idx_telefono_hash (telefono_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE import_duplicate_conflicts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    import_batch_id INT UNSIGNED NOT NULL,
    property_id BIGINT UNSIGNED NOT NULL,
    action_taken ENUM('kept_old','updated','pending') NOT NULL DEFAULT 'pending',
    resolved_by INT UNSIGNED NULL,
    resolved_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (import_batch_id) REFERENCES import_batches(id) ON DELETE CASCADE,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE property_notes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    property_id BIGINT UNSIGNED NOT NULL,
    author_id INT UNSIGNED NOT NULL,
    author_name_snapshot VARCHAR(200) NOT NULL,
    testo TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    FOREIGN KEY (author_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_property (property_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE property_assignments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    property_id BIGINT UNSIGNED NOT NULL,
    subuser_id INT UNSIGNED NOT NULL,
    assigned_by INT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    FOREIGN KEY (subuser_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_assignment (property_id, subuser_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE property_status_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    property_id BIGINT UNSIGNED NOT NULL,
    changed_by INT UNSIGNED NOT NULL,
    stato_precedente VARCHAR(50) NULL,
    stato_nuovo VARCHAR(50) NOT NULL,
    changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (property_id) REFERENCES properties(id) ON DELETE CASCADE,
    FOREIGN KEY (changed_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_property (property_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE registration_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    email_sent_to_admin TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE subuser_invitations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subuser_id INT UNSIGNED NOT NULL,
    invited_by INT UNSIGNED NOT NULL,
    token VARCHAR(255) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (subuser_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ade_import_jobs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provincia_sigla VARCHAR(4) NOT NULL,
    zip_filename VARCHAR(255) NOT NULL,
    status ENUM('queued','extracting','importing','verifying','completed','failed') NOT NULL DEFAULT 'queued',
    total_comuni INT UNSIGNED DEFAULT 0,
    processed_comuni INT UNSIGNED DEFAULT 0,
    total_particelle BIGINT UNSIGNED DEFAULT 0,
    processed_particelle BIGINT UNSIGNED DEFAULT 0,
    started_at DATETIME NULL,
    estimated_completion_at DATETIME NULL,
    completed_at DATETIME NULL,
    error_message TEXT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE ade_import_job_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    job_id INT UNSIGNED NOT NULL,
    level ENUM('info','warning','error') NOT NULL DEFAULT 'info',
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (job_id) REFERENCES ade_import_jobs(id) ON DELETE CASCADE,
    INDEX idx_job_id_id (job_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE user_sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    expires_at DATETIME NOT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
