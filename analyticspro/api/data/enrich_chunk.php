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
    if ($batchId === false || $batchId === null || $batchId < 0) {
        _enrich_error('invalid_param', 'Parametro batch_id non valido o mancante.');
    }
    $batchId = (int) $batchId;
    // batch_id = 0 → modalità globale: tutte le particelle con lat IS NULL del tenant

    $limit = min(100, max(1, (int) analyticspro_get('limit', '25')));

    $tenantId = analyticspro_current_tenant_id();
    $user = analyticspro_current_user();
    $pdo = analyticspro_db();

    if ($batchId > 0) {
        // Verifica che il batch appartenga all'utente corrente (sicurezza multi-tenant)
        $sql = 'SELECT id, enrichment_status, enrichment_sync FROM import_batches WHERE id = :id';
        $params = ['id' => $batchId];
        if (($user['role'] ?? '') !== 'admin') {
            if ($tenantId === null) {
                _enrich_error('auth_error', 'Tenant non disponibile per l\'utente corrente.');
            }
            $sql .= ' AND user_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }
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
            analyticspro_json([
                'ok' => true,
                'processed' => (int) ($row['enrichment_processed'] ?? 0),
                'total' => (int) ($row['enrichment_total'] ?? 0),
                'done' => true,
                'status' => $status,
                'enrichment_report' => $report,
            ]);
        }
    }

    $result = analyticspro_enrich_batch_coordinates_chunk($batchId, $limit);

    analyticspro_json([
        'ok' => true,
        'processed' => $result['processed'],
        'total' => $result['total'],
        'done' => $result['done'],
        'status' => $result['status'],
        'enrichment_report' => $result['enrichment_report'] ?? null,
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
