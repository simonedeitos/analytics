-- Stato persistente per gli step HTTP e il worker CLI condiviso.
ALTER TABLE gml_index_jobs
    ADD COLUMN comune_codes MEDIUMTEXT NULL COMMENT 'Snapshot JSON ordinato dei comuni del job',
    ADD COLUMN failed_comuni INT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN parcel_state TEXT NULL COMMENT 'Checkpoint JSON del comune in elaborazione',
    ADD COLUMN committed_particelle BIGINT UNSIGNED NOT NULL DEFAULT 0;

UPDATE gml_index_jobs SET committed_particelle = total_particelle;
