<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require_once ANALYTICSPRO_ROOT . '/includes/importer.php';

analyticspro_require_auth();
if (!analyticspro_is_admin()) {
    analyticspro_set_flash('danger', 'Accesso non consentito.');
    analyticspro_redirect('../dashboard.php');
}

$pdo = analyticspro_db();
$chunkResult = null;
$chunkError = null;

$runBatchId = isset($_GET['batch_id']) ? (int) $_GET['batch_id'] : 0;
$runLimit = isset($_GET['limit']) ? (int) $_GET['limit'] : 25;
$runLimit = max(1, min(100, $runLimit));

if (isset($_GET['run_chunk']) && $_GET['run_chunk'] === '1') {
    try {
        $chunkResult = analyticspro_enrich_batch_coordinates_chunk($runBatchId, $runLimit);
    } catch (Throwable $e) {
        $chunkError = $e;
    }
}

$columns = [
    'properties.coord_source' => false,
    'import_batches.enrichment_status' => false,
    'import_batches.enrichment_processed' => false,
    'import_batches.enrichment_total' => false,
    'import_batches.enrichment_report' => false,
    'import_batches.enrichment_sync' => false,
];

foreach ($columns as $key => $value) {
    [$table, $column] = explode('.', $key, 2);
    $stmt = $pdo->prepare('SHOW COLUMNS FROM ' . $table . ' LIKE :col');
    $stmt->execute(['col' => $column]);
    $columns[$key] = (bool) $stmt->fetch();
}

$nullGlobal = (int) $pdo->query('SELECT COUNT(*) FROM properties WHERE lat IS NULL')->fetchColumn();
$latestBatches = $pdo->query("SELECT id, user_id, status, enrichment_status, enrichment_processed, enrichment_total, created_at, updated_at FROM import_batches ORDER BY id DESC LIMIT 20")->fetchAll();

analyticspro_render_header('Diagnostica enrichment', ['app_assets' => false]);
?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <h1 class="h4 mb-3">Diagnostica enrichment coordinate</h1>
        <p class="text-muted small mb-0">Pagina di debug per analizzare 422 su enrich_chunk e problemi del pulsante "Rigenera coordinate".</p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <h2 class="h6">Verifica schema</h2>
        <ul class="small mb-0">
            <?php foreach ($columns as $name => $exists): ?>
                <li><?= analyticspro_h($name) ?>: <?= $exists ? '<span class="text-success">OK</span>' : '<span class="text-danger">MANCANTE</span>' ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <h2 class="h6">Stato globale</h2>
        <p class="mb-0 small">Immobili con coordinate mancanti (lat IS NULL): <strong><?= (int) $nullGlobal ?></strong></p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <h2 class="h6">Esegui un chunk manuale</h2>
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="run_chunk" value="1">
            <div class="col-md-3">
                <label class="form-label small">batch_id (0 = globale)</label>
                <input type="number" class="form-control form-control-sm" name="batch_id" value="<?= (int) $runBatchId ?>" min="0">
            </div>
            <div class="col-md-3">
                <label class="form-label small">limit</label>
                <input type="number" class="form-control form-control-sm" name="limit" value="<?= (int) $runLimit ?>" min="1" max="100">
            </div>
            <div class="col-md-3">
                <button class="btn btn-primary btn-sm" type="submit">Esegui chunk</button>
            </div>
        </form>
        <?php if ($chunkResult !== null): ?>
            <pre class="mt-3 bg-dark text-light p-2 rounded small"><?= analyticspro_h(json_encode($chunkResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
        <?php endif; ?>
        <?php if ($chunkError !== null): ?>
            <div class="alert alert-danger mt-3 mb-0 small">
                <strong>Errore:</strong> <?= analyticspro_h($chunkError->getMessage()) ?><br>
                <code><?= analyticspro_h($chunkError->getFile()) ?>:<?= (int) $chunkError->getLine() ?></code>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body">
        <h2 class="h6">Batch recenti</h2>
        <div class="table-responsive">
            <table class="table table-sm table-striped align-middle mb-0">
                <thead>
                <tr>
                    <th>ID</th><th>Tenant</th><th>Status</th><th>Enrichment</th><th>Progress</th><th>Aggiornato</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($latestBatches as $batch): ?>
                    <tr>
                        <td><?= (int) $batch['id'] ?></td>
                        <td><?= (int) $batch['user_id'] ?></td>
                        <td><?= analyticspro_h((string) $batch['status']) ?></td>
                        <td><?= analyticspro_h((string) ($batch['enrichment_status'] ?? '')) ?></td>
                        <td><?= (int) ($batch['enrichment_processed'] ?? 0) ?>/<?= (int) ($batch['enrichment_total'] ?? 0) ?></td>
                        <td><?= analyticspro_h((string) ($batch['updated_at'] ?? $batch['created_at'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php analyticspro_render_footer(false); ?>
