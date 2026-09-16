<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/api_bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/duplicate_merge_service.php';
require_once ANALYTICSPRO_ROOT . '/includes/maintenance_service.php';

analyticspro_api_guard();
analyticspro_api_require_auth();

if (!analyticspro_maintenance_access_allowed()) {
    analyticspro_json(['ok' => false, 'error' => 'Accesso consentito solo all\'utente principale.'], 403);
}

$completedInBatch = 0;
$logPath = null;

try {
    $input = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    analyticspro_verify_csrf($input['csrf_token'] ?? null);
    $tenantId = analyticspro_maintenance_resolve_requested_tenant_id($input['tenant_id'] ?? null);
    $clusters = is_array($input['clusters'] ?? null) ? $input['clusters'] : [];
    if ($clusters === []) {
        throw new RuntimeException('Nessun cluster da elaborare.');
    }

    $completedBefore = max(0, (int) ($input['completed_before'] ?? 0));
    $totalClusters = max(0, (int) ($input['total_clusters'] ?? count($clusters)));
    $summary = is_array($input['summary'] ?? null) ? $input['summary'] : [];
    $logPath = analyticspro_duplicate_merge_resolve_log_path(isset($input['log_file']) ? (string) $input['log_file'] : null);
    if ($completedBefore === 0 && !is_file($logPath)) {
        analyticspro_duplicate_merge_write_log($logPath, 'Modalità: APPLY — tenant filter: ' . ($tenantId !== null && $tenantId > 0 ? (string) $tenantId : 'tutti') . '. Log: ' . $logPath);
    }

    $results = [];
    foreach (array_values($clusters) as $index => $clusterInput) {
        $propertyIds = is_array($clusterInput['property_ids'] ?? null) ? $clusterInput['property_ids'] : [];
        $cluster = analyticspro_duplicate_merge_resolve_cluster_by_property_ids($propertyIds, $tenantId);
        $preview = analyticspro_preview_duplicate_property_merge($cluster);
        $globalIndex = $completedBefore + $completedInBatch + 1;
        analyticspro_duplicate_merge_write_log($logPath, sprintf(
            'Cluster %d/%d: keeper #%d, assorbiti [%s], owners=%d, notes=%d, assignments=%d',
            $globalIndex,
            max($totalClusters, $globalIndex),
            (int) ($preview['keeper']['id'] ?? 0),
            implode(', ', array_map('strval', $preview['absorbed_ids'] ?? [])),
            count($preview['owners'] ?? []),
            count($preview['notes'] ?? []),
            count($preview['assignments'] ?? [])
        ));
        $result = analyticspro_duplicate_merge_apply_cluster($cluster);
        $completedInBatch++;
        $results[] = $result + [
            'property_ids' => array_values(array_map(static fn (array $property): int => (int) ($property['id'] ?? 0), $cluster)),
            'cluster_number' => $globalIndex,
        ];
    }

    $completedTotal = $completedBefore + $completedInBatch;
    if ($completedTotal >= $totalClusters && $summary !== []) {
        analyticspro_duplicate_merge_write_log($logPath, sprintf(
            'Report finale: clusters=%d, properties coinvolte=%d, owners coinvolti=%d, notes coinvolte=%d, assignments coinvolte=%d',
            (int) ($summary['clusters'] ?? $totalClusters),
            (int) ($summary['properties'] ?? 0),
            (int) ($summary['owners'] ?? 0),
            (int) ($summary['notes'] ?? 0),
            (int) ($summary['assignments'] ?? 0)
        ));
    }

    analyticspro_json([
        'ok' => true,
        'processed_clusters' => $completedInBatch,
        'completed_clusters' => $completedTotal,
        'total_clusters' => $totalClusters,
        'log_file' => basename($logPath),
        'log_url' => analyticspro_base_url('manutenzione.php?download_log=' . rawurlencode(basename($logPath))),
        'results' => $results,
    ]);
} catch (Throwable $exception) {
    analyticspro_json([
        'ok' => false,
        'error' => $exception->getMessage(),
        'processed_clusters' => $completedInBatch,
        'log_file' => $logPath ? basename($logPath) : null,
        'log_url' => $logPath ? analyticspro_base_url('manutenzione.php?download_log=' . rawurlencode(basename($logPath))) : null,
    ], 422);
}
