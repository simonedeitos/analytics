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
    $payload = analyticspro_fetch_properties_payload(analyticspro_current_user(), $mode === 'assigned' ? 'assigned' : 'all', $filterSubuserId ?: null);
    analyticspro_json(['ok' => true] + $payload);
} catch (Throwable $exception) {
    analyticspro_json(['ok' => false, 'error' => $exception->getMessage()], 422);
}
