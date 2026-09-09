<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

function analyticspro_all_tenants_cron_authorized(): bool
{
    if (PHP_SAPI === 'cli') {
        return true;
    }

    $expected = (string) (analyticspro_env('ANALYTICSPRO_CRON_TOKEN', '') ?? '');
    $provided = (string) ($_GET['token'] ?? $_POST['token'] ?? '');

    return $expected !== '' && hash_equals($expected, $provided);
}

if (!analyticspro_all_tenants_cron_authorized()) {
    http_response_code(403);
    exit(1);
}

$lockDir = ANALYTICSPRO_ROOT . '/storage/locks';
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0775, true);
}
$lockHandle = fopen($lockDir . '/enrich_missing_coordinates_all_tenants.lock', 'c+');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo json_encode(['event' => 'enrich_missing_coordinates_all_tenants', 'status' => 'locked']) . PHP_EOL;
    exit(0);
}

$startedAt = microtime(true);
$maxSeconds = max(60, (int) (analyticspro_env('ANALYTICSPRO_ENRICH_ALL_TENANTS_MAX_SECONDS', '600') ?? '600'));
$maxParcels = max(1, (int) (analyticspro_env('ANALYTICSPRO_ENRICH_ALL_TENANTS_MAX_PARCELS', '400') ?? '400'));
$perTenantLimit = max(1, min(100, (int) (analyticspro_env('ANALYTICSPRO_ENRICH_ALL_TENANTS_PER_TENANT', '25') ?? '25')));

try {
    $pdo = analyticspro_db();
    $tenantSql = 'SELECT DISTINCT p.user_id
        FROM properties p
        WHERE ' . analyticspro_enrichment_recoverable_condition_sql('p') . '
        ORDER BY p.user_id ASC';
    $tenantIds = $pdo->query($tenantSql)->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $tenantIds = array_values(array_filter(array_map('intval', $tenantIds), static function (int $tenantId): bool {
        return $tenantId > 0;
    }));

    $processedUnique = 0;
    $tenantsTouched = [];

    while ($tenantIds !== [] && $processedUnique < $maxParcels && (microtime(true) - $startedAt) < $maxSeconds) {
        $nextTenantIds = [];
        foreach ($tenantIds as $tenantId) {
            if ($processedUnique >= $maxParcels || (microtime(true) - $startedAt) >= $maxSeconds) {
                $nextTenantIds[] = $tenantId;
                continue;
            }

            $beforeProgress = analyticspro_enrichment_fetch_progress($pdo, 0, $tenantId);
            $result = analyticspro_enrich_batch_coordinates_chunk(0, min($perTenantLimit, $maxParcels - $processedUnique), $tenantId);
            $afterProgress = analyticspro_enrichment_fetch_progress($pdo, 0, $tenantId);
            $delta = max(0, $afterProgress['processed'] - $beforeProgress['processed']);
            $processedUnique += $delta;
            if ($delta > 0) {
                $tenantsTouched[$tenantId] = true;
            }
            if (empty($result['done'])) {
                $nextTenantIds[] = $tenantId;
            }
        }
        if ($nextTenantIds === $tenantIds) {
            break;
        }
        $tenantIds = $nextTenantIds;
    }

    $after = analyticspro_fetch_missing_coordinate_stats(null);
    echo json_encode([
        'event' => 'enrich_missing_coordinates_all_tenants',
        'status' => 'completed',
        'tenants_touched' => array_keys($tenantsTouched),
        'processed_unique' => $processedUnique,
        'remaining_missing_rows' => $after['total'],
        'remaining_exhausted_rows' => $after['exhausted'],
        'duration_seconds' => round(microtime(true) - $startedAt, 3),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode([
        'event' => 'enrich_missing_coordinates_all_tenants',
        'status' => 'error',
        'message' => $exception->getMessage(),
        'duration_seconds' => round(microtime(true) - $startedAt, 3),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
} finally {
    if (is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
    }
}
