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

try {
    $input = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    analyticspro_verify_csrf($input['csrf_token'] ?? null);
    $tenantId = analyticspro_maintenance_resolve_requested_tenant_id($input['tenant_id'] ?? null);
    $offset = max(0, (int) ($input['offset'] ?? 0));
    $limit = max(0, (int) ($input['limit'] ?? 0));
    $scan = analyticspro_duplicate_merge_scan($tenantId, $offset, $limit);
    analyticspro_json([
        'ok' => true,
        'tenant_id' => $tenantId,
        'offset' => $scan['offset'],
        'limit' => $scan['limit'],
        'total_clusters' => $scan['total_clusters'],
        'source_properties' => $scan['source_properties'],
        'has_more' => $scan['has_more'],
        'summary' => $scan['summary'],
        'clusters' => array_map(static fn (array $item): array => $item['public'], $scan['clusters']),
    ]);
} catch (Throwable $exception) {
    analyticspro_json(['ok' => false, 'error' => $exception->getMessage()], 422);
}
