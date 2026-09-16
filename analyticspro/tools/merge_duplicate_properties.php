<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Questo script è utilizzabile solo da CLI.\n");
    exit(1);
}

require dirname(__DIR__) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/duplicate_merge_service.php';

$options = getopt('', ['tenant::', 'apply', 'dry-run']);
$tenantFilter = isset($options['tenant']) ? (int) $options['tenant'] : null;
$apply = array_key_exists('apply', $options);
$dryRun = !$apply || array_key_exists('dry-run', $options);
$logPath = analyticspro_duplicate_merge_create_log_path();

$log = static function (string $message) use ($logPath): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    echo $line . PHP_EOL;
    @file_put_contents($logPath, $line . PHP_EOL, FILE_APPEND);
};

try {
    $scan = analyticspro_duplicate_merge_scan($tenantFilter, 0, 0);
} catch (Throwable $exception) {
    $log($exception->getMessage());
    exit(1);
}

if (($scan['source_properties'] ?? 0) === 0) {
    $log('Nessuna property trovata per il filtro richiesto. Log: ' . $logPath);
    exit(0);
}

$log('Modalità: ' . ($dryRun ? 'DRY-RUN' : 'APPLY') . ' — tenant filter: ' . ($tenantFilter !== null && $tenantFilter > 0 ? (string) $tenantFilter : 'tutti') . '. Log: ' . $logPath);
$log('Cluster duplicati rilevati: ' . (int) ($scan['total_clusters'] ?? 0));

foreach ($scan['clusters'] as $clusterIndex => $item) {
    $preview = $item['preview'];
    $public = $item['public'];
    $log(sprintf(
        'Cluster %d: keeper #%d, assorbiti [%s], owners=%d, notes=%d, assignments=%d',
        $clusterIndex + 1,
        (int) ($public['keeper_id'] ?? 0),
        implode(', ', array_map('strval', $public['absorbed_ids'] ?? [])),
        (int) ($public['owners_count'] ?? 0),
        (int) ($public['notes_count'] ?? 0),
        (int) ($public['assignments_count'] ?? 0)
    ));

    if ($dryRun) {
        continue;
    }

    try {
        analyticspro_duplicate_merge_apply_cluster($item['cluster']);
    } catch (Throwable $exception) {
        $log('ERRORE cluster #' . ($clusterIndex + 1) . ': ' . $exception->getMessage());
        exit(1);
    }
}

$summary = $scan['summary'] ?? ['clusters' => 0, 'properties' => 0, 'owners' => 0, 'notes' => 0, 'assignments' => 0];
$log(sprintf(
    'Report finale: clusters=%d, properties coinvolte=%d, owners coinvolti=%d, notes coinvolte=%d, assignments coinvolte=%d',
    (int) ($summary['clusters'] ?? 0),
    (int) ($summary['properties'] ?? 0),
    (int) ($summary['owners'] ?? 0),
    (int) ($summary['notes'] ?? 0),
    (int) ($summary['assignments'] ?? 0)
));

exit(0);
