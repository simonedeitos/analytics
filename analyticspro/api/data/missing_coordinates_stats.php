<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/api_bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/importer.php';

analyticspro_api_guard();
analyticspro_api_require_auth();

try {
    $pdo = analyticspro_db();
    analyticspro_json(
        analyticspro_missing_coordinates_stats_payload(
            $pdo,
            analyticspro_is_admin(),
            analyticspro_current_tenant_id()
        )
    );
} catch (Throwable $exception) {
    analyticspro_json(['ok' => false, 'error' => $exception->getMessage()], 422);
}
