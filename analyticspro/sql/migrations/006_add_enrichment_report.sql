-- Migration 006: add structured enrichment report to import_batches.
ALTER TABLE import_batches
    ADD COLUMN enrichment_report JSON NULL AFTER enrichment_total;
