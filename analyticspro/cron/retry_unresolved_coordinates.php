<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

function analyticspro_retry_cron_authorized(): bool
{
    if (PHP_SAPI === 'cli') {
        return true;
    }

    $expected = (string) (analyticspro_env('ANALYTICSPRO_CRON_TOKEN', '') ?? '');
    $provided = (string) ($_GET['token'] ?? $_POST['token'] ?? '');

    return $expected !== '' && hash_equals($expected, $provided);
}

if (!analyticspro_retry_cron_authorized()) {
    http_response_code(403);
    exit(1);
}

$lockDir = ANALYTICSPRO_ROOT . '/storage/locks';
if (!is_dir($lockDir)) {
    @mkdir($lockDir, 0775, true);
}
$lockHandle = fopen($lockDir . '/retry_unresolved_coordinates.lock', 'c+');
if (!$lockHandle || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    echo json_encode(['event' => 'retry_unresolved_coordinates', 'status' => 'locked']) . PHP_EOL;
    exit(0);
}

$startedAt = microtime(true);
$maxRows = max(1, (int) (analyticspro_env('ANALYTICSPRO_DAILY_UNRESOLVED_LIMIT', '250') ?? '250'));
$chunkSize = max(1, min(100, (int) (analyticspro_env('ANALYTICSPRO_DAILY_UNRESOLVED_CHUNK', '25') ?? '25')));
$maxSeconds = max(30, (int) (analyticspro_env('ANALYTICSPRO_DAILY_UNRESOLVED_MAX_SECONDS', '600') ?? '600'));

try {
    $pdo = analyticspro_db();
    $before = analyticspro_fetch_missing_coordinate_stats(null);

    $setClauses = [];
    if (analyticspro_properties_has_enrichment_attempt_columns()) {
        $setClauses[] = 'enrichment_attempts = 0';
        $setClauses[] = 'enrichment_last_error_code = NULL';
        $setClauses[] = 'enrichment_last_error_note = NULL';
    }
    if (analyticspro_properties_has_coord_source_column()) {
        $setClauses[] = 'coord_source = NULL';
    }

    $unlockedRows = 0;
    if ($setClauses !== []) {
        $whereParts = ['lat IS NULL'];
        if (analyticspro_properties_has_coord_source_column()) {
            $whereParts[] = "coord_source = 'unresolved'";
        } elseif (analyticspro_properties_has_enrichment_attempt_columns()) {
            $whereParts[] = 'enrichment_attempts >= :max_attempts';
        }
        if (analyticspro_properties_has_enrichment_attempt_columns()) {
            $whereParts[] = '(enrichment_last_attempt_at IS NULL OR enrichment_last_attempt_at < NOW() - INTERVAL 1 DAY)';
        }
        $sql = 'UPDATE properties
            SET ' . implode(', ', $setClauses) . '
            WHERE ' . implode(' AND ', $whereParts) . '
            LIMIT ' . $maxRows;
        analyticspro_debug_assert_sql_params_match(
            $sql,
            analyticspro_properties_has_enrichment_attempt_columns() && !analyticspro_properties_has_coord_source_column()
                ? ['max_attempts' => (int) ANALYTICSPRO_ENRICH_MAX_ATTEMPTS]
                : []
        );
        $stmt = $pdo->prepare($sql);
        if (analyticspro_properties_has_enrichment_attempt_columns() && !analyticspro_properties_has_coord_source_column()) {
            $stmt->bindValue(':max_attempts', (int) ANALYTICSPRO_ENRICH_MAX_ATTEMPTS, PDO::PARAM_INT);
        }
        $stmt->execute();
        $unlockedRows = $stmt->rowCount();
    }

    $processedEstimate = 0;
    while ($processedEstimate < $maxRows && (microtime(true) - $startedAt) < $maxSeconds) {
        $beforeProgress = analyticspro_enrichment_fetch_progress($pdo, 0);
        $result = analyticspro_enrich_batch_coordinates_chunk(0, min($chunkSize, $maxRows - $processedEstimate));
        $afterProgress = analyticspro_enrichment_fetch_progress($pdo, 0);
        $delta = max(0, $afterProgress['processed'] - $beforeProgress['processed']);
        $processedEstimate += $delta;
        if (!empty($result['done']) || $delta === 0) {
            break;
        }
    }

    $after = analyticspro_fetch_missing_coordinate_stats(null);
    echo json_encode([
        'event' => 'retry_unresolved_coordinates',
        'status' => 'completed',
        'unlocked_rows' => $unlockedRows,
        'resolved_rows' => max(0, $before['total'] - $after['total']),
        'still_failed_rows' => $after['exhausted'],
        'processed_unique' => $processedEstimate,
        'duration_seconds' => round(microtime(true) - $startedAt, 3),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $exception) {
    echo json_encode([
        'event' => 'retry_unresolved_coordinates',
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
