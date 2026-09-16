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
    analyticspro_json(['ok' => true, 'migrations' => analyticspro_maintenance_get_migration_statuses()]);
} catch (Throwable $exception) {
    analyticspro_json(['ok' => false, 'error' => $exception->getMessage()], 422);
}
