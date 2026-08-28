-- Migration 006: add enrichment_sync flag to import_batches.
-- Set to 1 when the background enrichment worker cannot be launched
-- (proc_open/shell_exec disabled on shared hosting), enabling the
-- polling-based synchronous chunk fallback via api/data/enrich_chunk.php.
ALTER TABLE import_batches
    ADD COLUMN enrichment_sync TINYINT(1) NOT NULL DEFAULT 0 AFTER enrichment_total;
