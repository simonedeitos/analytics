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
} catch (Throwable $exception) {
    $message = $exception->getMessage();
    analyticspro_json([
        'ok' => false,
        'error' => $message,
        'blocked_by_duplicates' => str_contains($message, 'Migration 016 blocked:'),
        'section_hint' => str_contains($message, 'Migration 016 blocked:') ? 'duplicates' : null,
    ], 422);
}
