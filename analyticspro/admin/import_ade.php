<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require __DIR__ . '/_admin_check.php';

$pdo     = analyticspro_db();
$recentJobs = $pdo->query("SELECT j.id, j.provincia_sigla, j.zip_filename, j.status, j.total_comuni, j.processed_comuni, j.created_at, j.completed_at FROM ade_import_jobs j ORDER BY j.created_at DESC LIMIT 30")->fetchAll();

// Pre-fetch the last 5 log lines for each non-completed job in a single query
$nonCompletedIds = array_column(
    array_filter($recentJobs, static fn($j) => in_array($j['status'], ['extracting', 'importing', 'verifying', 'failed'], true)),
    'id'
);
$jobLogsByJob = [];
if ($nonCompletedIds) {
    // Fetch the most recent rows ordered descending, then invert per job in PHP
    $inPlaceholders = implode(',', array_fill(0, count($nonCompletedIds), '?'));
    $logStmt = $pdo->prepare("SELECT job_id, level, message, created_at FROM ade_import_job_log WHERE job_id IN ($inPlaceholders) ORDER BY id DESC");
    $logStmt->execute($nonCompletedIds);
    foreach ($logStmt->fetchAll() as $row) {
        if (!isset($jobLogsByJob[$row['job_id']]) || count($jobLogsByJob[$row['job_id']]) < 5) {
            $jobLogsByJob[$row['job_id']][] = $row;
        }
    }
}

analyticspro_render_header('Import ADE', ['app_assets' => true]);
require __DIR__ . '/_admin_subnav.php';
?>
<div id="analyticspro-app"
     data-ade-jobs-endpoint="<?= analyticspro_h(analyticspro_base_url('api/admin/ade_jobs.php')) ?>">

    <h1 class="h3 mb-4">Import cartografia ADE</h1>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2 class="h5">Carica ZIP provinciali</h2>
                    <p class="text-muted small mb-3">
                        Carica i file ZIP provinciali ADE (es. <code>NA.zip</code>). Il worker elabora
                        ricorsivamente i comuni annidati (<code>A024_ACERRA.zip</code> → GML) e
                        popola le tabelle <code>cadastral_comuni</code> e <code>cadastral_parcels</code>
                        nel database applicativo MySQL.
                    </p>
                    <input id="ade-zips" type="file" class="form-control mb-2" accept=".zip" multiple>
                    <div class="form-text mb-3">Puoi caricare una provincia per volta o più file contemporaneamente.</div>
                    <div id="ade-jobs" class="mt-2"></div>
                </div>
            </div>
        </div>
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2 class="h5">Job recenti</h2>
                    <?php if (!$recentJobs): ?>
                        <p class="text-muted mb-0">Nessun job di import effettuato.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm align-middle">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Provincia</th>
                                        <th>File</th>
                                        <th>Stato</th>
                                        <th>Comuni</th>
                                        <th>Avviato</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($recentJobs as $job): ?>
                                    <tr>
                                        <td><?= analyticspro_h((string) $job['id']) ?></td>
                                        <td><strong><?= analyticspro_h(strtoupper((string) $job['provincia_sigla'])) ?></strong></td>
                                        <td><code class="small"><?= analyticspro_h((string) $job['zip_filename']) ?></code></td>
                                        <td>
                                            <?php
                                            $statusColors = [
                                                'queued'     => 'secondary',
                                                'extracting' => 'info',
                                                'importing'  => 'primary',
                                                'verifying'  => 'warning',
                                                'completed'  => 'success',
                                                'failed'     => 'danger',
                                            ];
                                            $sc = $statusColors[$job['status']] ?? 'secondary';
                                            ?>
                                            <span class="badge bg-<?= analyticspro_h($sc) ?>"><?= analyticspro_h((string) $job['status']) ?></span>
                                        </td>
                                        <td><?= analyticspro_h((string) $job['processed_comuni']) ?>/<?= analyticspro_h((string) $job['total_comuni']) ?></td>
                                        <td class="text-muted small"><?= analyticspro_h((string) $job['created_at']) ?></td>
                                    </tr>
                                    <?php
                                    // Show last log lines for non-completed jobs (pre-fetched above)
                                    if (in_array($job['status'], ['extracting', 'importing', 'verifying', 'failed'], true)):
                                        $lines = array_reverse($jobLogsByJob[$job['id']] ?? []);
                                    ?>
                                    <tr>
                                        <td colspan="6" class="p-0">
                                            <div class="bg-dark text-white small p-2 font-monospace" style="font-size:.75rem;max-height:80px;overflow-y:auto;">
                                                <?php foreach ($lines as $line): ?>
                                                    <div class="text-<?= $line['level'] === 'error' ? 'danger' : ($line['level'] === 'warning' ? 'warning' : 'light') ?>">
                                                        [<?= analyticspro_h((string) $line['created_at']) ?>] <?= analyticspro_h((string) $line['message']) ?>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php analyticspro_render_footer(true); ?>
