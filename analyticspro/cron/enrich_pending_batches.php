<?php

declare(strict_types=1);

/**
 * Recovery cron script for orphaned coordinate-enrichment batches.
 *
 * Selects all import batches with enrichment_status = 'pending' or
 * enrichment_status = 'processing' for more than N minutes (indicating the
 * background worker died mid-run) and calls analyticspro_enrich_batch_coordinates()
 * for each one in sequence, with per-batch error isolation.
 *
 * OPERATIONAL REQUIREMENT — install this as a real cron job on the server:
 *
 *   * * * * * php /absolute/path/to/analyticspro/cron/enrich_pending_batches.php >> /absolute/path/to/enrich_pending.log 2>&1
 *
 * Running every minute ensures that batches not picked up by the background
 * worker (common on shared hosting where proc_open/shell_exec is disabled) are
 * retried within ~1 minute of the import completing.
 *
 * Adjust STALE_PROCESSING_MINUTES if your enrichment jobs legitimately take
 * longer than the default 15 minutes.
 */

require dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

/** Minutes after which a 'processing' batch is considered stale/dead. */
const STALE_PROCESSING_MINUTES = 15;

try {
    $pdo = analyticspro_db();
} catch (Throwable $e) {
    error_log('[enrich_pending_batches] DB connection failed: ' . $e->getMessage());
    exit(1);
}

// Fetch all pending batches plus stale processing batches.
try {
    $stmt = $pdo->prepare(
        "SELECT id FROM import_batches
         WHERE enrichment_status = 'pending'
            OR (
                enrichment_status = 'processing'
                AND enrichment_started_at IS NOT NULL
                AND enrichment_started_at < NOW() - INTERVAL " . STALE_PROCESSING_MINUTES . " MINUTE
            )
         ORDER BY id ASC"
    );
    $stmt->execute();
    $batchIds = $stmt->fetchAll(\PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    error_log('[enrich_pending_batches] Query failed: ' . $e->getMessage());
    exit(1);
}

if (empty($batchIds)) {
    exit(0);
}

echo '[enrich_pending_batches] Found ' . count($batchIds) . ' batch(es) to enrich.' . PHP_EOL;

foreach ($batchIds as $batchId) {
    $batchId = (int) $batchId;
    echo "[enrich_pending_batches] Processing batch #{$batchId}..." . PHP_EOL;
    try {
        analyticspro_enrich_batch_coordinates($batchId);
        echo "[enrich_pending_batches] Batch #{$batchId} completed." . PHP_EOL;
    } catch (Throwable $e) {
        // Error isolation: one failing batch must not prevent others from running.
        error_log("[enrich_pending_batches] Batch #{$batchId} failed: " . $e->getMessage());
        echo "[enrich_pending_batches] Batch #{$batchId} FAILED: " . $e->getMessage() . PHP_EOL;
    }
}

echo '[enrich_pending_batches] Done.' . PHP_EOL;
