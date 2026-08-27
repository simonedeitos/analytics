-- Run once on existing installations to optimize ADE job log polling by job_id + id.
ALTER TABLE ade_import_job_log
    ADD INDEX idx_job_id_id (job_id, id);
