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
    $recoverableCondition = analyticspro_enrichment_recoverable_condition_sql('p');
    $uniqueParcelExpr = analyticspro_enrichment_unique_parcel_expr('p');
    $detailSql = 'SELECT
            u.id AS tenant_id,
            TRIM(CONCAT(u.nome, \' \', u.cognome)) AS tenant_name,
            u.email AS tenant_email,
            COUNT(p.id) AS total,
            SUM(CASE WHEN p.id IS NOT NULL AND (' . $recoverableCondition . ') THEN 1 ELSE 0 END) AS recoverable,
            COUNT(DISTINCT CASE WHEN p.id IS NOT NULL THEN ' . $uniqueParcelExpr . ' ELSE NULL END) AS unique_parcels,
            COUNT(DISTINCT CASE WHEN p.id IS NOT NULL AND (' . $recoverableCondition . ') THEN ' . $uniqueParcelExpr . ' ELSE NULL END) AS unique_parcels_recoverable
        FROM users u
        LEFT JOIN properties p
          ON p.user_id = u.id
         AND p.lat IS NULL
        WHERE u.role = \'user\'
        GROUP BY u.id, u.nome, u.cognome, u.email
        ORDER BY total DESC, u.id ASC';
    $rows = $pdo->query($detailSql)->fetchAll() ?: [];

    $tenants = array_map(static function (array $row): array {
        $stats = analyticspro_missing_coordinate_stats_normalize(
            (int) ($row['total'] ?? 0),
            (int) ($row['recoverable'] ?? 0),
            (int) ($row['unique_parcels'] ?? 0),
            (int) ($row['unique_parcels_recoverable'] ?? 0)
        );
        return array_merge([
            'tenant_id' => (int) ($row['tenant_id'] ?? 0),
            'tenant_name' => trim((string) ($row['tenant_name'] ?? '')) !== ''
                ? trim((string) ($row['tenant_name'] ?? ''))
                : ('Tenant #' . (int) ($row['tenant_id'] ?? 0)),
            'tenant_email' => (string) ($row['tenant_email'] ?? ''),
        ], $stats);
    }, $rows);

    $tenantTotals = [
        'total' => 0,
        'recoverable' => 0,
        'unique_parcels' => 0,
        'unique_parcels_recoverable' => 0,
    ];
    foreach ($tenants as $tenant) {
        $tenantTotals['total'] += (int) ($tenant['total'] ?? 0);
        $tenantTotals['recoverable'] += (int) ($tenant['recoverable'] ?? 0);
        $tenantTotals['unique_parcels'] += (int) ($tenant['unique_parcels'] ?? 0);
        $tenantTotals['unique_parcels_recoverable'] += (int) ($tenant['unique_parcels_recoverable'] ?? 0);
    }
    $otherStats = analyticspro_missing_coordinate_stats_normalize(
        max(0, (int) ($overall['total'] ?? 0) - $tenantTotals['total']),
        max(0, (int) ($overall['recoverable'] ?? 0) - $tenantTotals['recoverable']),
        max(0, (int) ($overall['unique_parcels'] ?? 0) - $tenantTotals['unique_parcels']),
        max(0, (int) ($overall['unique_parcels_recoverable'] ?? 0) - $tenantTotals['unique_parcels_recoverable'])
    );
    if ($otherStats['total'] > 0 || $otherStats['unique_parcels'] > 0) {
        $tenants[] = array_merge([
            'tenant_id' => 0,
            'tenant_name' => 'Altri',
            'tenant_email' => '',
        ], $otherStats);
    }

    analyticspro_json([
        'ok' => true,
        'scope' => 'admin',
        'stats' => $overall,
        'tenants' => $tenants,
    ]);
} catch (Throwable $exception) {
    analyticspro_json(['ok' => false, 'error' => $exception->getMessage()], 422);
}
