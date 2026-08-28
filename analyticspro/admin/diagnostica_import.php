<?php

declare(strict_types=1);

/**
 * admin/diagnostica_import.php
 *
 * ⚠️  SOLO USO DIAGNOSTICO — rimuovere dopo il debug.
 */

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/importer.php';
require_once __DIR__ . '/../includes/gml_catalog.php';
require __DIR__ . '/_admin_check.php';

$pdo = analyticspro_db();

/**
 * @return array{rows: array<int, array<string, mixed>>, error: string|null}
 */
function analyticspro_diag_fetch_all(PDO $pdo, string $sql): array
{
    try {
        $rows = $pdo->query($sql)->fetchAll();
        return ['rows' => is_array($rows) ? $rows : [], 'error' => null];
    } catch (Throwable $exception) {
        return ['rows' => [], 'error' => $exception->getMessage()];
    }
}

function analyticspro_diag_column_exists(PDO $pdo, string $table, string $column): array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c LIMIT 1'
        );
        $stmt->execute(['t' => $table, 'c' => $column]);
        return ['check' => "$table.$column", 'ok' => (bool) $stmt->fetchColumn(), 'error' => null];
    } catch (Throwable $exception) {
        return ['check' => "$table.$column", 'ok' => false, 'error' => $exception->getMessage()];
    }
}

function analyticspro_diag_table_exists(PDO $pdo, string $table): array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1'
        );
        $stmt->execute(['t' => $table]);
        return ['check' => "TABLE:$table", 'ok' => (bool) $stmt->fetchColumn(), 'error' => null];
    } catch (Throwable $exception) {
        return ['check' => "TABLE:$table", 'ok' => false, 'error' => $exception->getMessage()];
    }
}

$belfioreInput = trim((string) analyticspro_get('comune_test', ''));
$belfioreResult = null;
$belfioreError = null;
if ($belfioreInput !== '') {
    try {
        $belfioreResult = analyticspro_resolve_cod_catastale('', $belfioreInput, '');
    } catch (Throwable $exception) {
        $belfioreError = $exception->getMessage();
    }
}

$enrichBatchId = (int) analyticspro_get('enrich_batch', 0);
$enrichResult = null;
$enrichError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && analyticspro_post('action') === 'enrich') {
    analyticspro_verify_csrf(analyticspro_post('csrf_token'));
    $enrichBatchId = (int) analyticspro_post('enrich_batch', '0');
    if ($enrichBatchId > 0) {
        try {
            $enrichResult = analyticspro_enrich_batch_coordinates_chunk($enrichBatchId, 10);
        } catch (Throwable $exception) {
            $enrichError = $exception;
        }
    }
}

$batchesResult = analyticspro_diag_fetch_all(
    $pdo,
    'SELECT id, user_id, filename, total_rows, processed_rows, status, enrichment_status,
            enrichment_processed, enrichment_total, enrichment_sync, created_at
     FROM import_batches ORDER BY id DESC LIMIT 20'
);
$batches = $batchesResult['rows'];
$batchesError = $batchesResult['error'];

$migrationChecks = [];
foreach ([
    ['import_batches', 'enrichment_status'],
    ['import_batches', 'enrichment_processed'],
    ['import_batches', 'enrichment_total'],
    ['import_batches', 'enrichment_report'],
    ['import_batches', 'enrichment_sync'],
    ['properties', 'coord_source'],
    ['particelle_cache', 'source'],
] as [$table, $column]) {
    $migrationChecks[] = analyticspro_diag_column_exists($pdo, $table, $column);
}

foreach (['import_batches', 'properties', 'property_owners', 'property_assignments', 'gml_index_jobs', 'import_duplicate_conflicts'] as $table) {
    $migrationChecks[] = analyticspro_diag_table_exists($pdo, $table);
}

analyticspro_render_header('Diagnostica Import', ['app_assets' => false]);
?>
<div class="container-fluid py-4">
    <div class="alert alert-warning">
        <strong>⚠️ Pagina diagnostica — solo admin.</strong>
        Rimuovere dopo il debug.
    </div>

    <h1 class="h3 mb-4">Diagnostica Import &amp; Enrichment</h1>

    <div class="card mb-4">
        <div class="card-header fw-semibold">Verifica migration 001-007</div>
        <div class="card-body p-0">
            <table class="table table-sm mb-0">
                <thead><tr><th>Elemento</th><th>Stato</th></tr></thead>
                <tbody>
                <?php foreach ($migrationChecks as $chk): ?>
                    <tr>
                        <td><code><?= analyticspro_h((string) $chk['check']) ?></code></td>
                        <td>
                            <?php if (!empty($chk['error'])): ?>
                                <span class="badge bg-warning text-dark">ERRORE CHECK</span>
                                <div class="small text-muted mt-1"><?= analyticspro_h((string) $chk['error']) ?></div>
                            <?php else: ?>
                                <?= $chk['ok']
                                    ? '<span class="badge bg-success">OK</span>'
                                    : '<span class="badge bg-danger">MANCANTE</span>' ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header fw-semibold">Test risoluzione Comune → Belfiore</div>
        <div class="card-body">
            <form method="get" class="d-flex gap-2 mb-3">
                <input type="text" name="comune_test" class="form-control form-control-sm"
                       placeholder="Nome comune (es. Milano)" value="<?= analyticspro_h($belfioreInput) ?>">
                <button class="btn btn-sm btn-secondary">Risolvi</button>
            </form>
            <?php if ($belfioreError !== null): ?>
                <div class="alert alert-danger small mb-0"><?= analyticspro_h($belfioreError) ?></div>
            <?php elseif ($belfioreResult !== null): ?>
                <pre class="bg-light p-3 rounded small"><?= analyticspro_h((string) json_encode($belfioreResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header fw-semibold">Batch recenti (ultimi 20)</div>
        <div class="card-body p-0">
            <?php if ($batchesError !== null): ?>
                <div class="alert alert-warning m-3 mb-0 small">Query batch non disponibile: <?= analyticspro_h($batchesError) ?></div>
            <?php endif; ?>
            <div class="table-responsive">
                <table class="table table-sm table-striped mb-0" style="font-size:.8rem">
                    <thead>
                        <tr>
                            <th>ID</th><th>File</th><th>Righe</th><th>Status</th>
                            <th>Enrich status</th><th>Proc/Tot</th><th>Sync</th><th>Data</th><th>Azione</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($batches as $b): ?>
                        <tr>
                            <td><?= (int) ($b['id'] ?? 0) ?></td>
                            <td><?= analyticspro_h((string) ($b['filename'] ?? '')) ?></td>
                            <td><?= (int) ($b['processed_rows'] ?? 0) ?>/<?= (int) ($b['total_rows'] ?? 0) ?></td>
                            <td><?= analyticspro_h((string) ($b['status'] ?? '')) ?></td>
                            <td><?= analyticspro_h((string) ($b['enrichment_status'] ?? '—')) ?></td>
                            <td><?= (int) ($b['enrichment_processed'] ?? 0) ?>/<?= (int) ($b['enrichment_total'] ?? 0) ?></td>
                            <td><?= !empty($b['enrichment_sync']) ? '✓' : '' ?></td>
                            <td><?= analyticspro_h((string) ($b['created_at'] ?? '')) ?></td>
                            <td>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= analyticspro_h(analyticspro_csrf_token()) ?>">
                                    <input type="hidden" name="action" value="enrich">
                                    <input type="hidden" name="enrich_batch" value="<?= (int) ($b['id'] ?? 0) ?>">
                                    <button class="btn btn-xs btn-outline-primary py-0 px-1" style="font-size:.75rem">
                                        Enrich chunk
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$batches): ?>
                        <tr><td colspan="9" class="text-center text-muted py-3">Nessun batch disponibile.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <?php if ($enrichResult !== null || $enrichError !== null): ?>
    <div class="card mb-4">
        <div class="card-header fw-semibold">Risultato enrich_chunk per batch #<?= $enrichBatchId ?></div>
        <div class="card-body">
            <?php if ($enrichError !== null): ?>
                <div class="alert alert-danger">
                    <strong><?= analyticspro_h(get_class($enrichError)) ?>:</strong>
                    <?= analyticspro_h($enrichError->getMessage()) ?>
                </div>
                <pre class="bg-light p-3 rounded small"><?= analyticspro_h($enrichError->getTraceAsString()) ?></pre>
            <?php else: ?>
                <pre class="bg-light p-3 rounded small"><?= analyticspro_h((string) json_encode($enrichResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>
<?php analyticspro_render_footer(false); ?>
