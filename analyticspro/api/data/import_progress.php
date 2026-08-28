<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/api_bootstrap.php';

analyticspro_api_guard();

analyticspro_api_require_auth();

/**
 * @return array<string, true>
 */
function analyticspro_import_batches_columns_progress(PDO $pdo): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }
    $cached = [];
    try {
        foreach ($pdo->query('SHOW COLUMNS FROM import_batches') ?: [] as $row) {
            if (isset($row['Field'])) {
                $cached[(string) $row['Field']] = true;
            }
        }
    } catch (Throwable $exception) {
        error_log('[import_progress] schema_read_error: ' . $exception->getMessage());
    }
    return $cached;
}

try {
    $batchId = (int) analyticspro_get('batch_id');
    if ($batchId <= 0) {
        throw new RuntimeException('Batch non valido.');
    }

    $tenantId = analyticspro_current_tenant_id();
    $user = analyticspro_current_user();
    $pdo = analyticspro_db();
    $columns = analyticspro_import_batches_columns_progress($pdo);
    $hasUserId = isset($columns['user_id']);
    $sql = 'SELECT * FROM import_batches WHERE id = :id';
    $params = ['id' => $batchId];
    if (($user['role'] ?? '') !== 'admin' && $hasUserId) {
        $sql .= ' AND user_id = :tenant_id';
        $params['tenant_id'] = $tenantId;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $batch = $stmt->fetch();
    if (!$batch) {
        if (($user['role'] ?? '') === 'admin') {
            throw new RuntimeException('batch_not_found: Batch non trovato.');
        }
        throw new RuntimeException('permission_denied: Batch non accessibile per il tenant corrente.');
    }

    if (($user['role'] ?? '') !== 'admin' && !$hasUserId) {
        $scopeStmt = $pdo->prepare('SELECT COUNT(*) FROM properties WHERE import_batch_id = :batch_id AND user_id = :tenant_id');
        $scopeStmt->execute(['batch_id' => $batchId, 'tenant_id' => $tenantId]);
        if ((int) $scopeStmt->fetchColumn() === 0) {
            throw new RuntimeException('permission_denied: Batch non accessibile per il tenant corrente.');
        }
    }

    $batch['progress_percent'] = (int) round(((int) $batch['processed_rows'] / max((int) $batch['total_rows'], 1)) * 100);

    // Expose enrichment progress fields so the frontend can poll and display
    // background geocoding status.
    $batch['enrichment_status']    = $batch['enrichment_status']    ?? null;
    $batch['enrichment_processed'] = (int) ($batch['enrichment_processed'] ?? 0);
    $batch['enrichment_total']     = (int) ($batch['enrichment_total']     ?? 0);
    $enrichmentReportRaw           = $batch['enrichment_report'] ?? null;
    $batch['enrichment_report']    = null;
    if (is_string($enrichmentReportRaw) && trim($enrichmentReportRaw) !== '') {
        $decoded = json_decode($enrichmentReportRaw, true);
        $batch['enrichment_report'] = is_array($decoded) ? $decoded : null;
    }

    analyticspro_json(['ok' => true, 'batch' => $batch]);
} catch (Throwable $exception) {
    $message = $exception->getMessage();
    $errorCode = 'db_error';
    if (str_starts_with($message, 'batch_not_found:')) {
        $errorCode = 'batch_not_found';
        $message = trim(substr($message, strlen('batch_not_found:')));
    } elseif (str_starts_with($message, 'permission_denied:')) {
        $errorCode = 'permission_denied';
        $message = trim(substr($message, strlen('permission_denied:')));
    } elseif ($exception instanceof PDOException && (string) $exception->getCode() === '42S22') {
        $errorCode = 'missing_column';
    }
    error_log('[import_progress] ' . $errorCode . ': ' . $message);
    analyticspro_json(['ok' => false, 'error_code' => $errorCode, 'error' => $message], 404);
}
