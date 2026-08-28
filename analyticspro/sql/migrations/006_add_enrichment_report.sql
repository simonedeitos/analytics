-- Migration 006: add structured enrichment report to import_batches.
ALTER TABLE import_batches
    ADD COLUMN IF NOT EXISTS enrichment_report JSON NULL AFTER enrichment_total;
