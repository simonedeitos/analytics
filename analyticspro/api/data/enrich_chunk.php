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

try {
    $batchId = (int) analyticspro_get('batch_id');
    if ($batchId < 0) {
        throw new RuntimeException('Parametro batch_id non valido.');
    }
    // batch_id = 0 → modalità globale: tutte le particelle con lat IS NULL del tenant

    $limit = min(100, max(1, (int) analyticspro_get('limit', '25')));

    $tenantId = analyticspro_current_tenant_id();
    $user     = analyticspro_current_user();
    $pdo      = analyticspro_db();

    if ($batchId > 0) {
        // Verifica che il batch appartenga all'utente corrente (sicurezza multi-tenant)
        $sql    = 'SELECT id, enrichment_status, enrichment_sync FROM import_batches WHERE id = :id';
        $params = ['id' => $batchId];
        if (($user['role'] ?? '') !== 'admin') {
            $sql .= ' AND user_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $batch = $stmt->fetch();
        if (!$batch) {
            throw new RuntimeException('Batch non trovato.');
        }

        $status = $batch['enrichment_status'] ?? 'pending';

        // Se già completato o fallito, restituisci solo lo stato senza elaborare
        if (in_array($status, ['completed', 'failed'], true)) {
            $stateStmt = $pdo->prepare(
                'SELECT enrichment_status, enrichment_processed, enrichment_total FROM import_batches WHERE id = :id'
            );
            $stateStmt->execute(['id' => $batchId]);
            $row = $stateStmt->fetch() ?: [];
            analyticspro_json([
                'ok'        => true,
                'processed' => (int) ($row['enrichment_processed'] ?? 0),
                'total'     => (int) ($row['enrichment_total']     ?? 0),
                'done'      => true,
                'status'    => $status,
            ]);
        }
    }

    // Elabora il chunk
    $result = analyticspro_enrich_batch_coordinates_chunk($batchId, $limit);

    analyticspro_json([
        'ok'        => true,
        'processed' => $result['processed'],
        'total'     => $result['total'],
        'done'      => $result['done'],
        'status'    => $result['status'],
    ]);
} catch (Throwable $exception) {
    analyticspro_json(['ok' => false, 'error' => $exception->getMessage()], 422);
}
