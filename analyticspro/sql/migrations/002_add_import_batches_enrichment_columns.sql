-- Migration 002: add enrichment progress columns to import_batches
-- These columns track the async WFS coordinate enrichment phase that runs
-- after the main import persistence is complete.

ALTER TABLE import_batches
    ADD COLUMN enrichment_status  ENUM('pending','processing','completed','failed') NOT NULL DEFAULT 'pending' AFTER completed_at,
    ADD COLUMN enrichment_processed INT UNSIGNED NOT NULL DEFAULT 0 AFTER enrichment_status,
    ADD COLUMN enrichment_total     INT UNSIGNED NOT NULL DEFAULT 0 AFTER enrichment_processed;
