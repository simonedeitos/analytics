<?php

declare(strict_types=1);

function analyticspro_load_env(string $path): void
{
    if (!is_file($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lines)) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($value !== '' && (($value[0] === '"' && substr($value, -1) === '"') || ($value[0] === "'" && substr($value, -1) === "'"))) {
            $value = substr($value, 1, -1);
        }

        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

function analyticspro_h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function analyticspro_base_url(string $path = ''): string
{
    $base = rtrim(analyticspro_env('APP_URL', '/analyticspro'), '/');
    return $path === '' ? $base : $base . '/' . ltrim($path, '/');
}

function analyticspro_redirect(string $path): never
{
    header('Location: ' . analyticspro_base_url($path));
    exit;
}

function analyticspro_set_flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function analyticspro_take_flash(): array
{
    $flash = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : [];
}

function analyticspro_json(array $payload, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function analyticspro_post(string $key, mixed $default = null): mixed
{
    return $_POST[$key] ?? $default;
}

function analyticspro_get(string $key, mixed $default = null): mixed
{
    return $_GET[$key] ?? $default;
}

function analyticspro_system_config(string $key, ?string $default = null): ?string
{
    static $cache = [];
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = analyticspro_db()->prepare('SELECT `value` FROM system_config WHERE `key` = :key LIMIT 1');
        $stmt->execute(['key' => $key]);
        $value = $stmt->fetchColumn();
        $cache[$key] = $value === false ? $default : (string) $value;
    } catch (Throwable $exception) {
        $cache[$key] = $default;
    }

    return $cache[$key];
}

function analyticspro_save_system_config(array $values): void
{
    $pdo = analyticspro_db();
    $stmt = $pdo->prepare('INSERT INTO system_config (`key`, `value`) VALUES (:key, :value) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    foreach ($values as $key => $value) {
        $stmt->execute([
            'key' => $key,
            'value' => $value,
        ]);
    }
}

function analyticspro_normalize_hash_value(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    return mb_strtoupper($value, 'UTF-8');
}

function analyticspro_split_phone_values(?string $raw): array
{
    $raw = trim((string) $raw);
    if ($raw === '') {
        return [];
    }

    $parts = preg_split('/[;,]/', $raw) ?: [];
    $phones = [];
    $seen = [];
    foreach ($parts as $part) {
        $value = trim((string) $part);
        if ($value === '') {
            continue;
        }
        if (!isset($seen[$value])) {
            $seen[$value] = true;
            $phones[] = $value;
        }
    }

    return $phones;
}

function analyticspro_remove_phone_value(?string $raw, ?string $phoneToRemove): ?string
{
    $original = $raw;
    $phones = analyticspro_split_phone_values($raw);
    $phoneToRemove = trim((string) $phoneToRemove);
    if ($phoneToRemove === '') {
        return trim((string) $original) === '' ? null : $original;
    }

    $updated = [];
    $removed = false;
    foreach ($phones as $phone) {
        if (!$removed && $phone === $phoneToRemove) {
            $removed = true;
            continue;
        }
        $updated[] = $phone;
    }

    if (!$removed) {
        return trim((string) $original) === '' ? null : $original;
    }

    return $updated ? implode(';', $updated) : null;
}

function analyticspro_random_password(int $length = 12): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    $password = '';
    $max = strlen($alphabet) - 1;
    for ($i = 0; $i < $length; $i++) {
        $password .= $alphabet[random_int(0, $max)];
    }
    return $password;
}

function analyticspro_state_options(): array
{
    return [
        'non_interessato' => 'Non Interessato',
        'interessato' => 'Interessato',
        'contattato' => 'Contattato',
        'da_contattare' => 'Da Contattare',
        'in_vendita_noi' => 'In Vendita NOI',
        'in_vendita_altri' => 'In Vendita ALTRI',
        'altro' => 'Altro',
    ];
}

function analyticspro_state_colors(): array
{
    return [
        'non_interessato' => '#dc3545',
        'interessato' => '#198754',
        'contattato' => '#0dcaf0',
        'da_contattare' => '#0d6efd',
        'in_vendita_noi' => '#fd7e14',
        'in_vendita_altri' => '#ffc107',
        'altro' => '#6f42c1',
    ];
}

function analyticspro_marker_color_palette(): array
{
    return [
        'Rosso' => '#dc3545',
        'Arancio' => '#fd7e14',
        'Giallo' => '#ffc107',
        'Verde' => '#198754',
        'Azzurro' => '#0dcaf0',
        'Blu' => '#0d6efd',
        'Fucsia' => '#d63384',
        'Viola' => '#6f42c1',
    ];
}

function analyticspro_allowed_marker_colors(): array
{
    return array_values(analyticspro_marker_color_palette());
}

function analyticspro_default_color_for_state(string $state): string
{
    $colors = analyticspro_state_colors();
    return $colors[$state] ?? '#0d6efd';
}

function analyticspro_full_name(array $user): string
{
    return trim(($user['nome'] ?? '') . ' ' . ($user['cognome'] ?? ''));
}

function analyticspro_client_ip(): ?string
{
    return $_SERVER['REMOTE_ADDR'] ?? null;
}

function analyticspro_current_role(): ?string
{
    $role = analyticspro_current_user()['role'] ?? null;
    return is_string($role) ? $role : null;
}

function analyticspro_is_admin(): bool
{
    return analyticspro_current_role() === 'admin';
}

function analyticspro_is_main_user(): bool
{
    return analyticspro_current_role() === 'user';
}

function analyticspro_is_subuser(): bool
{
    return analyticspro_current_role() === 'subuser';
}

function analyticspro_current_tenant_id(): ?int
{
    $user = analyticspro_current_user();
    if (!$user) {
        return null;
    }

    if (($user['role'] ?? '') === 'user') {
        return (int) $user['id'];
    }

    if (($user['role'] ?? '') === 'subuser') {
        return isset($user['parent_user_id']) ? (int) $user['parent_user_id'] : null;
    }

    if (($user['role'] ?? '') === 'admin') {
        $selected = analyticspro_get('tenant_id');
        if ($selected === 'all' || $selected === null || $selected === '') {
            return null;
        }
        return (int) $selected;
    }

    return null;
}

function analyticspro_tenant_phone_visibility(?int $tenantId = null): bool
{
    $tenantId ??= analyticspro_current_tenant_id();
    if ($tenantId === null) {
        return analyticspro_is_admin();
    }

    $stmt = analyticspro_db()->prepare('SELECT can_view_phone FROM users WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $tenantId]);
    return (bool) $stmt->fetchColumn();
}

function analyticspro_get_subuser_permissions(int $subuserId): array
{
    $stmt = analyticspro_db()->prepare('SELECT can_edit_all_markers, can_import, can_view_analytics, can_view_reports, can_export FROM subuser_permissions WHERE subuser_id = :id LIMIT 1');
    $stmt->execute(['id' => $subuserId]);
    $permissions = $stmt->fetch() ?: [];

    return array_merge([
        'can_edit_all_markers' => 0,
        'can_import' => 0,
        'can_view_analytics' => 0,
        'can_view_reports' => 0,
        'can_export' => 1,
    ], $permissions);
}

function analyticspro_require_permission(string $permission): void
{
    $user = analyticspro_current_user();
    if (!$user) {
        analyticspro_redirect('login.php');
    }

    if (($user['role'] ?? '') !== 'subuser') {
        return;
    }

    $permissions = analyticspro_get_subuser_permissions((int) $user['id']);
    if (empty($permissions[$permission])) {
        throw new RuntimeException('Permesso insufficiente per questa operazione.');
    }
}

function analyticspro_fetch_tenants(): array
{
    $stmt = analyticspro_db()->query("SELECT id, nome, cognome, email, can_view_phone, status FROM users WHERE role = 'user' ORDER BY cognome, nome");
    return $stmt->fetchAll();
}

function analyticspro_fetch_subusers(int $tenantId): array
{
    $stmt = analyticspro_db()->prepare("SELECT u.*, p.can_edit_all_markers, p.can_import, p.can_view_analytics, p.can_view_reports, p.can_export FROM users u LEFT JOIN subuser_permissions p ON p.subuser_id = u.id WHERE u.parent_user_id = :tenant_id AND u.role = 'subuser' ORDER BY u.cognome, u.nome");
    $stmt->execute(['tenant_id' => $tenantId]);
    return $stmt->fetchAll();
}

function analyticspro_lookup_cod_catastale(string $comune, string $provincia): ?string
{
    require_once __DIR__ . '/importer.php';
    $resolved = analyticspro_resolve_cod_catastale('', $comune, $provincia);
    return is_string($resolved['cod'] ?? null) ? $resolved['cod'] : null;
}

function analyticspro_launch_background(string $script, array $arguments = []): bool
{
    $phpBinary = PHP_BINARY ?: 'php';
    $escapedArgs = array_map(static fn ($arg) => escapeshellarg((string) $arg), $arguments);
    $command = sprintf('%s %s %s', escapeshellcmd($phpBinary), escapeshellarg($script), implode(' ', $escapedArgs));
    $detachedCommand = $command . ' > /dev/null 2>&1 < /dev/null';
    $shellWrapper = 'sh -c ' . escapeshellarg(
        'if command -v setsid >/dev/null 2>&1; then exec setsid ' . $detachedCommand . '; else exec nohup ' . $detachedCommand . '; fi'
    ) . ' >/dev/null 2>&1 &';

    // Prova con proc_open, preferendo setsid (se disponibile) per staccare il
    // processo in una nuova sessione; fallback a nohup sugli hosting più limitati.
    if (function_exists('proc_open')) {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'a'],
            2 => ['file', '/dev/null', 'a'],
        ];

        $process = @proc_open($shellWrapper, $descriptors, $pipes, ANALYTICSPRO_ROOT);
        if (is_resource($process)) {
            foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
            }
            // Non chiamare proc_close(): bloccherebbe fino al termine del processo.
            return true;
        }
    }

    // Fallback: shell_exec, se disponibile.
    if (function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
        shell_exec($shellWrapper);
        return true;
    }

    return false;
}

function analyticspro_count_pending_registrations(): int
{
    try {
        $pdo = analyticspro_db();
        $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending'");
        return (int) ($stmt ? $stmt->fetchColumn() : 0);
    } catch (Throwable) {
        return 0;
    }
}
