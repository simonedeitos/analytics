<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/api_bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/importer.php';

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

        // Write the payload to disk so the cron script can be used for manual/diagnostic
        // re-processing if needed.  Phase 1 itself runs synchronously below.
        $payloadPath = ANALYTICSPRO_ROOT . '/storage/import_payloads/import_' . $batchId . '.json';
        file_put_contents($payloadPath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // Phase 1: persist cadastral data synchronously in this very HTTP request so the
        // outcome (success or failure) is immediate and certain.
        try {
            analyticspro_process_import_batch_payload($batchId, $payload);
        } catch (Throwable $e) {
            analyticspro_json(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        // Phase 2: coordinate enrichment — eseguita nella stessa richiesta HTTP.
        // Il lookup GML è locale (nessuna chiamata di rete per file già indicizzati)
        // e deduplicato per particella distinta, quindi il costo è trascurabile.
        // set_time_limit(0) garantisce che import di grandi file non vadano in timeout.
        set_time_limit(0);
        try {
            analyticspro_enrich_batch_coordinates($batchId);
        } catch (Throwable) {
            // Errore non fatale: i dati catastali sono già salvati, le coordinate
            // resteranno NULL e potranno essere rigenerate con "Rigenera coordinate mancanti".
        }

        $savedStmt = $pdo->prepare('SELECT processed_rows FROM import_batches WHERE id = ?');
        $savedStmt->execute([$batchId]);
        $saved = (int) $savedStmt->fetchColumn();

        // Carica il report di enrichment per restituirlo insieme alla risposta
        $enrichReport = null;
        try {
            $enrichReport = analyticspro_enrichment_report_load($pdo, $batchId);
        } catch (Throwable) {
        }

        analyticspro_json([
            'ok'               => true,
            'batch_id'         => $batchId,
            'saved_rows'       => $saved,
            'total_rows'       => count($rows),
            'enrichment_done'  => true,
            'enrichment_report' => $enrichReport,
        ]);
    }

    throw new RuntimeException('Modalità import non valida.');
} catch (Throwable $exception) {
    analyticspro_json(['ok' => false, 'error' => $exception->getMessage()], 422);
}
