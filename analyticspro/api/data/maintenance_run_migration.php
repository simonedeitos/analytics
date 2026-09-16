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
    $result = analyticspro_maintenance_run_migration((string) ($input['filename'] ?? ''));
    analyticspro_json(['ok' => true] + $result);
} catch (AnalyticsproMaintenanceException $exception) {
    analyticspro_json([
        'ok' => false,
        'error' => $exception->getMessage(),
        'error_code' => $exception->errorCode(),
        'blocked_by_duplicates' => $exception->errorCode() === 'migration_016_duplicates_blocked',
        'section_hint' => $exception->errorCode() === 'migration_016_duplicates_blocked' ? 'duplicates' : null,
    ], 422);
} catch (Throwable $exception) {
    analyticspro_json([
        'ok' => false,
        'error' => $exception->getMessage(),
        'error_code' => 'migration_run_failed',
        'blocked_by_duplicates' => false,
        'section_hint' => null,
    ], 422);
}
