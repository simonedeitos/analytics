<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/api_bootstrap.php';

analyticspro_api_guard();

analyticspro_api_require_auth();

try {
    $batchId = (int) analyticspro_get('batch_id');
    if ($batchId <= 0) {
        throw new RuntimeException('Batch non valido.');
    }

    $tenantId = analyticspro_current_tenant_id();
    $user = analyticspro_current_user();
    $sql = 'SELECT * FROM import_batches WHERE id = :id';
    $params = ['id' => $batchId];
    if (($user['role'] ?? '') !== 'admin') {
        $sql .= ' AND user_id = :tenant_id';
        $params['tenant_id'] = $tenantId;
    }

    $stmt = analyticspro_db()->prepare($sql);
    $stmt->execute($params);
    $batch = $stmt->fetch();
    if (!$batch) {
        throw new RuntimeException('Batch non trovato.');
    }

    $batch['progress_percent'] = (int) round(((int) $batch['processed_rows'] / max((int) $batch['total_rows'], 1)) * 100);
    analyticspro_json(['ok' => true, 'batch' => $batch]);
} catch (Throwable $exception) {
    analyticspro_json(['ok' => false, 'error' => $exception->getMessage()], 404);
}
