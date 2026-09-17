<?php

declare(strict_types=1);

/**
 * Test: errore in enrichment sincrono non blocca l'import (fallback + ok true).
 *
 * Exit code: 0 = pass, 1 = fail.
 */

require dirname(__DIR__) . '/includes/importer.php';

$fallback = [
    'geolocated' => 0,
    'processed_unique' => 0,
    'total_unique' => 5,
    'remaining_unique' => 5,
    'done' => false,
    'enrichment_sync' => false,
    'coord_source' => [],
    'attempt_failures' => [],
    'failure_codes' => [],
    'unresolved_rows' => [],
    'truncated' => false,
    'missing_comuni' => [],
    'missing_comuni_truncated' => false,
    'resolved' => 0,
    'unresolved' => 0,
    'geolocated_rows' => 0,
    'missing_rows' => 0,
];

$outcome = analyticspro_import_try_sync_enrichment(
    123,
    2000,
    $fallback,
    static function (int $batchId, int $maxUnique): array {
        if ($batchId <= 0 || $maxUnique <= 0) {
            throw new RuntimeException('parametri inattesi');
        }
        throw new RuntimeException('provider down');
    }
);

$response = [
    'ok' => true,
    'enrichment_done' => (bool) (($outcome['enrichment']['done'] ?? false)),
];

$pass = true;
$errors = [];

if (($outcome['failed'] ?? null) !== true) {
    $pass = false;
    $errors[] = 'failed dovrebbe essere true quando il sync callable solleva eccezione';
}
if (($outcome['enrichment']['remaining_unique'] ?? null) !== 5) {
    $pass = false;
    $errors[] = 'fallback enrichment non restituito correttamente';
}
if (($response['ok'] ?? null) !== true) {
    $pass = false;
    $errors[] = "la risposta import dovrebbe restare ok=true";
}
if (($response['enrichment_done'] ?? null) !== false) {
    $pass = false;
    $errors[] = "enrichment_done dovrebbe essere false in fallback";
}

if ($pass) {
    echo "PASS: import sync enrichment fallback non-blocking OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: $error\n";
}
exit(1);
