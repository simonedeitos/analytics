<?php

declare(strict_types=1);

define('ANALYTICSPRO_ROOT', dirname(__DIR__));
require_once ANALYTICSPRO_ROOT . '/includes/functions.php';
require_once ANALYTICSPRO_ROOT . '/includes/maintenance_service.php';

function analyticspro_current_user(): ?array { return $GLOBALS['user']; }

foreach ([null, ['id' => 1, 'role' => 'user'], ['id' => 2, 'role' => 'subuser']] as $user) {
    if (analyticspro_maintenance_migration_access_allowed()) {
        throw new RuntimeException('Only admins may run migrations.');
    }
    try {
        analyticspro_maintenance_run_migration('nonexistent.sql');
        throw new RuntimeException('Unauthorized migration was not rejected.');
    } catch (AnalyticsproMaintenanceException $exception) {
        if ($exception->errorCode() !== 'forbidden' || !str_contains($exception->getMessage(), 'amministratori')) {
            throw new RuntimeException('Expected a clear authorization failure before any SQL/file access.');
        }
    }
}
$user = ['id' => 1, 'role' => 'user'];
if (!analyticspro_maintenance_access_allowed() || analyticspro_maintenance_resolve_requested_tenant_id(99) !== 1) {
    throw new RuntimeException('Tenant-scoped duplicate maintenance must remain available.');
}
$user = ['id' => 4, 'role' => 'admin'];
if (!analyticspro_maintenance_migration_access_allowed()) {
    throw new RuntimeException('Admin access must be preserved.');
}
try {
    analyticspro_maintenance_run_migration('nonexistent.sql');
} catch (RuntimeException $exception) {
    if ($exception instanceof AnalyticsproMaintenanceException && $exception->errorCode() === 'forbidden') {
        throw new RuntimeException('Admin must reach migration lookup.');
    }
}

foreach (['maintenance_run_migration.php', 'maintenance_migrations.php'] as $endpoint) {
    foreach (['user', 'subuser'] as $role) {
        $code = 'define("ANALYTICSPRO_BOOTSTRAPPED", true);'
            . 'define("ANALYTICSPRO_ROOT", ' . var_export(ANALYTICSPRO_ROOT, true) . ');'
            . 'require ANALYTICSPRO_ROOT . "/includes/functions.php";'
            . 'function analyticspro_current_user(): array { return ["id" => 1, "role" => ' . var_export($role, true) . ']; }'
            . 'function analyticspro_api_require_auth(): void {}'
            . 'register_shutdown_function(function () { echo "\nSTATUS:" . http_response_code(); });'
            . 'require ANALYTICSPRO_ROOT . "/api/data/' . $endpoint . '";';
        $process = proc_open([PHP_BINARY, '-r', $code], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => STDERR], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start endpoint test.');
        }
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $exitCode = proc_close($process);
        [$json, $status] = explode("\nSTATUS:", $output);
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if ($exitCode !== 0 || $status !== '403' || $payload['error_code'] !== 'forbidden' || $payload['ok'] !== false) {
            throw new RuntimeException('Migration endpoint must return 403 for non-admins: ' . $endpoint);
        }
    }
}
echo "PASS: migration admin authorization and 403 endpoint guards\n";
