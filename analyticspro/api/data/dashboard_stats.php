<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/api_bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/dashboard_stats.php';

analyticspro_api_guard();
$user = analyticspro_api_require_auth();

try {
    analyticspro_verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? analyticspro_get('csrf_token'));

    $period = analyticspro_dashboard_period_key((string) analyticspro_get('period', 'all'));
    $province = trim((string) analyticspro_get('province', ''));
    $category = trim((string) analyticspro_get('category', ''));
    $refresh = analyticspro_get('refresh') === '1';
    $cacheKey = sha1(json_encode([
        'user' => (int) ($user['id'] ?? 0),
        'tenant' => analyticspro_current_tenant_id(),
        'selected' => analyticspro_get('tenant_id', ''),
        'period' => $period,
        'province' => $province,
        'category' => $category,
        'analytics' => !analyticspro_is_subuser() || !empty(analyticspro_get_subuser_permissions((int) $user['id'])['can_view_analytics']),
        'phone' => analyticspro_tenant_phone_visibility(analyticspro_current_tenant_id()),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    $cached = $_SESSION['analyticspro_dashboard_stats_cache'][$cacheKey] ?? null;
    if (!$refresh && is_array($cached) && (int) ($cached['expires_at'] ?? 0) >= time()) {
        analyticspro_json(['ok' => true] + ($cached['payload'] ?? []));
    }

    $payload = analyticspro_fetch_dashboard_stats($user, [
        'period' => $period,
        'province' => $province,
        'category' => $category,
        'map_limit' => 250,
    ]);

    $_SESSION['analyticspro_dashboard_stats_cache'][$cacheKey] = [
        'expires_at' => time() + 15,
        'payload' => $payload,
    ];

    analyticspro_json(['ok' => true] + $payload);
} catch (Throwable $exception) {
    analyticspro_json(['ok' => false, 'error' => $exception->getMessage()], 422);
}
