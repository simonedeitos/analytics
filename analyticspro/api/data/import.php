<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/api_bootstrap.php';

analyticspro_api_guard();

analyticspro_api_require_auth();
if (analyticspro_is_subuser()) {
    analyticspro_require_permission('can_import');
}

try {
    $input = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    analyticspro_verify_csrf($input['csrf_token'] ?? null);
    $mode = $input['mode'] ?? 'analyze';
    $rows = $input['rows'] ?? [];
    if (!is_array($rows) || $rows === []) {
        throw new RuntimeException('Nessuna riga da importare.');
    }

    $tenantId = analyticspro_current_tenant_id();
    $user = analyticspro_current_user();
    if ($tenantId === null || !$user) {
        throw new RuntimeException('Tenant non disponibile.');
    }

    if ($mode === 'analyze') {
        $conflicts = analyticspro_find_conflicts($rows, $tenantId);
        analyticspro_json(['ok' => true, 'conflicts' => $conflicts]);
    }

    if ($mode === 'process') {
        $filename = trim((string) ($input['filename'] ?? 'import.csv'));
        $decisions = is_array($input['decisions'] ?? null) ? $input['decisions'] : [];
        $pdo = analyticspro_db();
        $stmt = $pdo->prepare("INSERT INTO import_batches (user_id, uploaded_by, filename, total_rows, processed_rows, status) VALUES (:user_id, :uploaded_by, :filename, :total_rows, 0, 'processing')");
        $stmt->execute([
            'user_id' => $tenantId,
            'uploaded_by' => $user['id'],
            'filename' => $filename,
            'total_rows' => count($rows),
        ]);
        $batchId = (int) $pdo->lastInsertId();

        $payload = [
            'tenant_id' => $tenantId,
            'uploaded_by' => $user['id'],
            'rows' => $rows,
            'decisions' => $decisions,
        ];
        $payloadPath = ANALYTICSPRO_ROOT . '/storage/import_payloads/import_' . $batchId . '.json';
        file_put_contents($payloadPath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $worker = ANALYTICSPRO_ROOT . '/cron/process_import_batch.php';
        if (!analyticspro_launch_background($worker, [$batchId, $payloadPath])) {
            analyticspro_process_import_batch_payload($batchId, $payload);
            // process_import_batch.php launches enrichment on its own when run as a
            // background worker; when we use the sync fallback we must do it here.
            $enrichWorker = ANALYTICSPRO_ROOT . '/cron/enrich_property_coordinates.php';
            if (!analyticspro_launch_background($enrichWorker, [$batchId])) {
                analyticspro_enrich_batch_coordinates($batchId);
            }
        }

        analyticspro_json(['ok' => true, 'batch_id' => $batchId]);
    }

    throw new RuntimeException('Modalità import non valida.');
} catch (Throwable $exception) {
    analyticspro_json(['ok' => false, 'error' => $exception->getMessage()], 422);
}
