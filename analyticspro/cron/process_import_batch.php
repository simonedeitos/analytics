<?php
// NOTE: Phase 1 (property/owner persistence) is now always executed synchronously
// inside the HTTP request (api/data/import.php).  This script is kept as a manual
// diagnostic tool only — it can be used to re-run Phase 1 for a batch whose payload
// file is still on disk, but it is no longer invoked as part of the normal import flow.

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$batchId = isset($argv[1]) ? (int) $argv[1] : 0;
$payloadPath = $argv[2] ?? '';
if ($batchId <= 0 || !is_file($payloadPath)) {
    exit(1);
}

$payload = json_decode((string) file_get_contents($payloadPath), true);
if (!is_array($payload)) {
    analyticspro_db()->prepare("UPDATE import_batches SET status = 'failed', error_message = 'Payload import non valido', completed_at = NOW() WHERE id = :id")
        ->execute(['id' => $batchId]);
    exit(1);
}

try {
    analyticspro_process_import_batch_payload($batchId, $payload);
} finally {
    @unlink($payloadPath);
}

// Launch the async coordinate enrichment worker for this batch.
$enrichWorker = ANALYTICSPRO_ROOT . '/cron/enrich_property_coordinates.php';
if (!analyticspro_launch_background($enrichWorker, [$batchId])) {
    // Background launch unavailable — run enrichment synchronously in the same process.
    analyticspro_enrich_batch_coordinates($batchId);
}
