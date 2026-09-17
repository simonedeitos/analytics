<?php

declare(strict_types=1);

/**
 * api/data/enrich_chunk.php
 *
 * Elabora un singolo lotto sincrono (chunk) di N particelle per l'enrichment
 * coordinate di un batch di import.
 *
 * Parametri GET:
 *   batch_id  (int, obbligatorio)  — ID del batch da arricchire (0 = modalità globale)
 *   limit     (int, opzionale)     — Numero massimo di particelle per chunk (default: 25, max: 100)
 *
 * Risposta di errore strutturata:
 *   { ok: false, error_code: "...", error: "...", [file, line, trace] }
 *
 * Il frontend DEVE fermarsi (non riprovare) quando error_code non è "transient".
 */

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/api_bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/importer.php';

analyticspro_api_guard();
analyticspro_api_require_auth();
if (analyticspro_is_subuser()) {
    analyticspro_require_permission('can_import');
}

/**
 * Emette una risposta di errore strutturata e termina.
 *
 * @param string         $errorCode Codice macchina (batch_not_found, invalid_param, internal_error, transient, …)
 * @param string         $message   Messaggio leggibile in italiano
 * @param Throwable|null $ex        Eccezione originale (solo in APP_DEBUG)
 * @param int            $status    Codice HTTP (default 422)
 */
function _enrich_error(string $errorCode, string $message, ?Throwable $ex = null, int $status = 422): never
{
    $body = ['ok' => false, 'error_code' => $errorCode, 'error' => $message];
    if ($ex !== null && (defined('APP_DEBUG') && APP_DEBUG)) {
        $body['file'] = $ex->getFile();
        $body['line'] = $ex->getLine();
        $body['trace'] = $ex->getTraceAsString();
    }
    analyticspro_json($body, $status);
}

try {
    $batchId = filter_var(analyticspro_get('batch_id'), FILTER_VALIDATE_INT);
    if ($batchId === false || $batchId === null) {
        _enrich_error('invalid_param', 'Parametro batch_id non valido o mancante.');
    }
    $batchId = (int) $batchId;

    $limit = min(100, max(1, (int) analyticspro_get('limit', '25')));

    $isAdmin = analyticspro_is_admin();
    $tenantId = analyticspro_current_tenant_id();
    $user = analyticspro_current_user();
    $pdo = analyticspro_db();
    $scope = analyticspro_enrich_chunk_authorize_scope($batchId, $isAdmin, $tenantId, (bool) $user);
    if (!($scope['ok'] ?? false)) {
        _enrich_error(
            (string) ($scope['error_code'] ?? 'forbidden'),
            (string) ($scope['error'] ?? 'Operazione non consentita.'),
            null,
            (int) ($scope['status'] ?? 403)
        );
    }
    $chunkTenantId = $scope['chunk_tenant_id'] ?? null;

    if (($scope['requires_batch_lookup'] ?? false) === true) {
        // Verifica ownership tenant del batch. In import_batches.user_id è salvato il tenant_id.
        $sql = 'SELECT id, enrichment_status, enrichment_sync FROM import_batches WHERE id = :id';
        $params = ['id' => $batchId];
        if (!$isAdmin) {
            $sql .= ' AND user_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }
        analyticspro_debug_assert_sql_params_match($sql, $params);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $batch = $stmt->fetch();
        if (!$batch) {
            _enrich_error('batch_not_found', 'Batch non trovato o non autorizzato.');
        }

        $status = $batch['enrichment_status'] ?? 'pending';

        // Se già completato o fallito, restituisci solo lo stato senza elaborare
        if (in_array($status, ['completed', 'failed'], true)) {
            $stateStmt = $pdo->prepare(
                'SELECT enrichment_status, enrichment_processed, enrichment_total, enrichment_report FROM import_batches WHERE id = :id'
            );
            $stateStmt->execute(['id' => $batchId]);
            $row = $stateStmt->fetch() ?: [];
            $report = null;
            if (is_string($row['enrichment_report'] ?? null) && trim((string) $row['enrichment_report']) !== '') {
                $decoded = json_decode((string) $row['enrichment_report'], true);
                $report = is_array($decoded) ? $decoded : null;
            }
            $reconciliation = analyticspro_enrichment_fetch_reconciliation($pdo, $batchId);
            analyticspro_json([
                'ok' => true,
                'processed' => (int) ($row['enrichment_processed'] ?? 0),
                'total' => (int) ($row['enrichment_total'] ?? 0),
                'remaining' => 0,
                'resolved' => 0,
                'unresolved' => 0,
                'done' => true,
                'status' => $status,
                'enrichment_report' => $report,
                'total_rows' => $reconciliation['total_rows'],
                'geolocated_rows' => $reconciliation['geolocated_rows'],
                'missing_rows' => $reconciliation['missing_rows'],
            ]);
        }
    }

    $result = analyticspro_enrich_batch_coordinates_chunk($batchId, $limit, $chunkTenantId);

    analyticspro_json([
        'ok' => true,
        'processed' => $result['processed'],
        'total' => $result['total'],
        'remaining' => $result['remaining'] ?? 0,
        'resolved' => $result['resolved'] ?? 0,
        'unresolved' => $result['unresolved'] ?? 0,
        'done' => $result['done'],
        'status' => $result['status'],
        'enrichment_report' => $result['enrichment_report'] ?? null,
        'total_rows' => (int) ($result['total_rows'] ?? 0),
        'geolocated_rows' => (int) ($result['geolocated_rows'] ?? 0),
        'missing_rows' => (int) ($result['missing_rows'] ?? 0),
    ]);
} catch (Throwable $exception) {
    $msg = $exception->getMessage();
    $code = 'internal_error';

    if (
        str_contains($msg, 'CURLE_OPERATION_TIMEDOUT') ||
        str_contains($msg, 'Connection refused') ||
        str_contains($msg, 'temporarily unavailable')
    ) {
        $code = 'transient';
    }

    _enrich_error($code, 'Errore interno durante l\'enrichment: ' . $msg, $exception);
}
