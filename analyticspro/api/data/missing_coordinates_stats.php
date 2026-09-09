<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/api_bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/importer.php';

analyticspro_api_guard();
analyticspro_api_require_auth();

try {
    $pdo = analyticspro_db();

    if (!analyticspro_is_admin()) {
        $tenantId = analyticspro_current_tenant_id();
        if ($tenantId === null) {
            throw new RuntimeException('Tenant non disponibile.');
        }

        analyticspro_json([
            'ok' => true,
            'scope' => 'tenant',
            'stats' => analyticspro_fetch_missing_coordinate_stats($tenantId),
        ]);
    }

    $overall = analyticspro_fetch_missing_coordinate_stats(null);
    $detailSql = 'SELECT
            u.id AS tenant_id,
            TRIM(CONCAT(u.nome, \' \', u.cognome)) AS tenant_name,
            u.email AS tenant_email,
            COUNT(p.id) AS total,
            SUM(CASE WHEN ' . analyticspro_enrichment_recoverable_condition_sql('p') . ' THEN 1 ELSE 0 END) AS recoverable
        FROM users u
        LEFT JOIN properties p
          ON p.user_id = u.id
         AND p.lat IS NULL
        WHERE u.role = \'user\'
        GROUP BY u.id, u.nome, u.cognome, u.email
        ORDER BY total DESC, u.id ASC';
    $rows = $pdo->query($detailSql)->fetchAll() ?: [];

    $tenants = array_map(static function (array $row): array {
        $total = (int) ($row['total'] ?? 0);
        $recoverable = (int) ($row['recoverable'] ?? 0);
        return [
            'tenant_id' => (int) ($row['tenant_id'] ?? 0),
            'tenant_name' => trim((string) ($row['tenant_name'] ?? '')) !== ''
                ? trim((string) ($row['tenant_name'] ?? ''))
                : ('Tenant #' . (int) ($row['tenant_id'] ?? 0)),
            'tenant_email' => (string) ($row['tenant_email'] ?? ''),
            'total' => $total,
            'recoverable' => $recoverable,
            'exhausted' => max(0, $total - $recoverable),
        ];
    }, $rows);

    analyticspro_json([
        'ok' => true,
        'scope' => 'admin',
        'stats' => $overall,
        'tenants' => $tenants,
    ]);
} catch (Throwable $exception) {
    analyticspro_json(['ok' => false, 'error' => $exception->getMessage()], 422);
}
