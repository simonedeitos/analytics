<?php

declare(strict_types=1);

/**
 * api/data/enrich_chunk.php
 *
 * Elabora un singolo lotto sincrono (chunk) di N particelle per l'enrichment
 * coordinate di un batch di import.
 *
 * Usato come fallback quando il worker in background non può essere avviato
 * (proc_open/shell_exec disabilitati su hosting condiviso).
 * Viene chiamato ripetutamente dal frontend finché tutte le particelle sono
 * risolte o si raggiunge un limite di tentativi.
 *
 * Parametri GET:
 *   batch_id  (int, obbligatorio)  — ID del batch da arricchire
 *   limit     (int, opzionale)     — Numero massimo di particelle per chunk (default: 25, max: 100)
 */

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/api_bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/importer.php';

analyticspro_api_guard();
analyticspro_api_require_auth();

/**
 * @return array<string, true>
 */
function analyticspro_import_batches_columns(PDO $pdo): array
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
        error_log('[enrich_chunk] Impossibile leggere lo schema di import_batches: ' . $exception->getMessage());
    }

    return $cached;
}

try {
    $batchId = (int) analyticspro_get('batch_id');
    $requestedScopeBatchId = (int) analyticspro_get('scope_batch_id', 0);
    if ($batchId < 0) {
        throw new RuntimeException('Parametro batch_id non valido.');
    }
    if ($requestedScopeBatchId < 0) {
        throw new RuntimeException('Parametro scope_batch_id non valido.');
    }
    // batch_id = 0 → modalità globale: tutte le particelle con lat IS NULL del tenant

    $limit = min(100, max(1, (int) analyticspro_get('limit', '25')));

    $tenantId = analyticspro_current_tenant_id();
    $user     = analyticspro_current_user();
    $pdo      = analyticspro_db();
    $scopeBatchId = 0;
    $batchForValidation = $batchId > 0 ? $batchId : $requestedScopeBatchId;

    if ($batchForValidation > 0) {
        $columns = analyticspro_import_batches_columns($pdo);
        $hasUserId = isset($columns['user_id']);

        // Verifica batch con query resiliente (nessuna dipendenza da colonne opzionali).
        $sql    = 'SELECT * FROM import_batches WHERE id = :id';
        $params = ['id' => $batchForValidation];
        if (($user['role'] ?? '') !== 'admin' && $hasUserId) {
            $sql .= ' AND user_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $batch = $stmt->fetch();
        if (!$batch) {
            if (($user['role'] ?? '') === 'admin') {
                error_log('[enrich_chunk] batch_not_found: id=' . $batchForValidation);
                analyticspro_json(['ok' => false, 'error_code' => 'batch_not_found', 'error' => 'Batch non trovato.'], 422);
            }

            error_log('[enrich_chunk] permission_denied_or_batch_not_found: id=' . $batchForValidation . ', tenant=' . (string) $tenantId);
            analyticspro_json(['ok' => false, 'error_code' => 'permission_denied', 'error' => 'Batch non accessibile per il tenant corrente.'], 422);
        }

        if (($user['role'] ?? '') !== 'admin' && !$hasUserId) {
            if ($tenantId === null) {
                error_log('[enrich_chunk] permission_denied: tenant assente per batch=' . $batchForValidation);
                analyticspro_json(['ok' => false, 'error_code' => 'permission_denied', 'error' => 'Tenant non disponibile.'], 422);
            }
            $scopeStmt = $pdo->prepare('SELECT COUNT(*) FROM properties WHERE import_batch_id = :batch_id AND user_id = :tenant_id');
            $scopeStmt->execute(['batch_id' => $batchForValidation, 'tenant_id' => $tenantId]);
            if ((int) $scopeStmt->fetchColumn() === 0) {
                error_log('[enrich_chunk] permission_denied: batch=' . $batchForValidation . ', tenant=' . $tenantId . ', motivo=nessun_immobile_visibile');
                analyticspro_json(['ok' => false, 'error_code' => 'permission_denied', 'error' => 'Batch non accessibile per il tenant corrente.'], 422);
            }
        }

        $status = $batch['enrichment_status'] ?? 'pending';
        $scopeBatchId = $batchForValidation;

        // Se già completato o fallito, restituisci solo lo stato senza elaborare
        if (in_array($status, ['completed', 'failed'], true)) {
            $row = $batch;
            $report = null;
            if (is_string($row['enrichment_report'] ?? null) && trim((string) $row['enrichment_report']) !== '') {
                $decoded = json_decode((string) $row['enrichment_report'], true);
                $report = is_array($decoded) ? $decoded : null;
            }
            analyticspro_json([
                'ok'        => true,
                'processed' => (int) ($row['enrichment_processed'] ?? 0),
                'total'     => (int) ($row['enrichment_total']     ?? 0),
                'done'      => true,
                'status'    => $status,
                'enrichment_report' => $report,
            ]);
        }
    }

    // Elabora il chunk. batch_id>0 usa lo stesso percorso logico della modalità
    // "Rigenera coordinate mancanti" (batch_id=0), ma limitando il perimetro
    // al batch validato sopra.
    $result = analyticspro_enrich_batch_coordinates_chunk(0, $limit, $scopeBatchId > 0 ? $scopeBatchId : null);

    analyticspro_json([
        'ok'        => true,
        'processed' => $result['processed'],
        'total'     => $result['total'],
        'done'      => $result['done'],
        'status'    => $result['status'],
        'enrichment_report' => $result['enrichment_report'] ?? null,
    ]);
} catch (PDOException $exception) {
    $message = $exception->getMessage();
    $errorCode = 'db_error';
    if ((string) $exception->getCode() === '42S22') {
        $errorCode = 'missing_column';
    }
    error_log('[enrich_chunk] ' . $errorCode . ': ' . $message);
    analyticspro_json(['ok' => false, 'error_code' => $errorCode, 'error' => $message], 422);
} catch (Throwable $exception) {
    error_log('[enrich_chunk] db_error: ' . $exception->getMessage());
    analyticspro_json(['ok' => false, 'error_code' => 'db_error', 'error' => $exception->getMessage()], 422);
}
