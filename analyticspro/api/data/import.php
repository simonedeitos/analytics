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
    if ($mode !== 'manual_create' && (!is_array($rows) || $rows === [])) {
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

    if ($mode === 'process' || $mode === 'manual_create') {
        if ($mode === 'manual_create') {
            $manualRow = is_array($input['row'] ?? null) ? $input['row'] : [];
            if ($manualRow === []) {
                throw new RuntimeException('Record manuale non valido.');
            }
            $rows = [$manualRow];
        }

        $filename = trim((string) ($input['filename'] ?? ($mode === 'manual_create' ? 'inserimento_manuale' : 'import.csv')));
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
            'uploaded_by_name' => analyticspro_full_name($user),
            'rows' => $rows,
            'decisions' => $decisions,
        ];

        // Write the payload to disk so the cron script can be used for manual/diagnostic
        // re-processing if needed.  Phase 1 itself runs synchronously below.
        $payloadPath = ANALYTICSPRO_ROOT . '/storage/import_payloads/import_' . $batchId . '.json';
        file_put_contents($payloadPath, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        // Phase 1: persist cadastral data synchronously in this very HTTP request so the
        // outcome (success or failure) is immediate and certain.  A background worker is
        // no longer needed for this phase.
        try {
            $processSummary = analyticspro_process_import_batch_payload($batchId, $payload);
        } catch (Throwable $e) {
            analyticspro_json(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        // Phase 2: enrichment nella stessa richiesta HTTP (sincrono), con soglia
        // di sicurezza sulle particelle uniche per evitare timeout.
        $syncLimit = (int) (analyticspro_env('IMPORT_SYNC_MAX_UNIQUE', '50000') ?? '50000');
        $syncLimit = max(1, $syncLimit);
        if (function_exists('set_time_limit')) {
            @set_time_limit(30);
        }
        $enrichment = analyticspro_enrich_batch_coordinates_sync($batchId, $syncLimit);

        analyticspro_json([
            'ok' => true,
            'batch_id' => $batchId,
            'saved_rows' => (int) ($processSummary['saved_rows'] ?? 0),
            'total_rows' => count($rows),
            'processed_rows' => (int) ($processSummary['processed_rows'] ?? count($rows)),
            'skipped_rows' => (int) ($processSummary['skipped_rows'] ?? 0),
            'skipped_reasons' => $processSummary['skipped_reasons'] ?? [],
            'notes_imported' => (int) ($processSummary['notes_imported'] ?? 0),
            'geolocated_parcels' => (int) ($enrichment['geolocated'] ?? 0),
            'processed_parcels' => (int) ($enrichment['processed_unique'] ?? 0),
            'total_unique_parcels' => (int) ($enrichment['total_unique'] ?? 0),
            'remaining_unique_parcels' => (int) ($enrichment['remaining_unique'] ?? 0),
            'enrichment_done' => (bool) ($enrichment['done'] ?? false),
            'enrichment_sync' => (bool) ($enrichment['enrichment_sync'] ?? false),
            'coord_source' => $enrichment['coord_source'] ?? [],
            'failure_codes' => $enrichment['failure_codes'] ?? [],
            'unresolved_rows' => $enrichment['unresolved_rows'] ?? [],
            'unresolved_truncated' => (bool) ($enrichment['truncated'] ?? false),
        ]);
    }

    throw new RuntimeException('Modalità import non valida.');
} catch (Throwable $exception) {
    analyticspro_json(['ok' => false, 'error' => $exception->getMessage()], 422);
}
