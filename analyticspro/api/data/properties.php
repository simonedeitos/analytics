<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/api_bootstrap.php';

analyticspro_api_guard();
require_once ANALYTICSPRO_ROOT . '/includes/property_repository.php';

analyticspro_api_require_auth();

try {
    $mode = analyticspro_get('mode', 'all');
    $filterSubuserId = (int) analyticspro_get('subuser_id', 0);
    $requestedIds = null;
    if (isset($_GET['property_ids'])) {
        $requestedIds = array_values(array_filter(array_unique(array_map('intval', explode(',', (string) analyticspro_get('property_ids', '')))), static fn (int $id): bool => $id > 0));
        if ($requestedIds === []) {
            throw new RuntimeException('Immobili non validi.');
        }
    }
    $payload = analyticspro_fetch_properties_payload(
        analyticspro_current_user(),
        $mode === 'assigned' ? 'assigned' : 'all',
        $filterSubuserId ?: null,
        $requestedIds,
        analyticspro_get('view', '') === 'map'
    );
    analyticspro_json(['ok' => true] + $payload);
} catch (Throwable $exception) {
    analyticspro_json(['ok' => false, 'error' => $exception->getMessage()], 422);
}
