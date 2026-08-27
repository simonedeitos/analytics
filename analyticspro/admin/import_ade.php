<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require __DIR__ . '/_admin_check.php';

$pdo     = analyticspro_db();
$recentJobs = $pdo->query("SELECT j.id, j.provincia_sigla, j.zip_filename, j.status, j.total_comuni, j.processed_comuni, j.total_particelle, j.processed_particelle, j.created_at, j.completed_at, j.error_message FROM ade_import_jobs j ORDER BY j.created_at DESC LIMIT 30")->fetchAll();
$sqlUploadMax = trim((string) ini_get('upload_max_filesize')) ?: 'n/d';
$sqlPostMax = trim((string) ini_get('post_max_size')) ?: 'n/d';

analyticspro_render_header('Import ADE', ['app_assets' => true]);
require __DIR__ . '/_admin_subnav.php';
?>
<div id="analyticspro-app"
     data-ade-jobs-endpoint="<?= analyticspro_h(analyticspro_base_url('api/admin/ade_jobs.php')) ?>"
     data-ade-manual-files-endpoint="<?= analyticspro_h(analyticspro_base_url('api/admin/ade_manual_files.php')) ?>">

    <h1 class="h3 mb-4">Import cartografia ADE</h1>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2 class="h5">Importa ZIP provinciali</h2>

                    <!-- Nav tabs -->
                    <ul class="nav nav-tabs mb-3" id="ade-import-tabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="tab-upload-btn" data-bs-toggle="tab"
                                    data-bs-target="#tab-upload" type="button" role="tab">
                                <i class="bi bi-cloud-upload me-1"></i>Upload dal browser
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-server-btn" data-bs-toggle="tab"
                                    data-bs-target="#tab-server" type="button" role="tab">
                                <i class="bi bi-hdd me-1"></i>File sul server
                            </button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-sql-btn" data-bs-toggle="tab"
                                    data-bs-target="#tab-sql" type="button" role="tab">
                                <i class="bi bi-database me-1"></i>Importa file SQL pre-elaborato
                            </button>
                        </li>
                    </ul>

                    <div class="tab-content">
                        <!-- Tab: upload da browser -->
                        <div class="tab-pane fade show active" id="tab-upload" role="tabpanel">
                            <p class="text-muted small mb-3">
                                Carica i file ZIP provinciali ADE (es. <code>BS.zip</code>). Il worker elabora
                                ricorsivamente i comuni annidati e popola le tabelle
                                <code>cadastral_comuni</code> e <code>cadastral_parcels</code>.
                            </p>
                            <input id="ade-zips" type="file" class="form-control mb-2" accept=".zip" multiple>
                            <button id="ade-zips-submit" class="btn btn-primary mt-2" type="button" disabled>
                                <i class="bi bi-cloud-upload me-1"></i>Importa
                            </button>
                            <div class="form-text mb-3">Puoi caricare una provincia per volta o più file contemporaneamente.</div>
                        </div>

                        <!-- Tab: file già sul server -->
                        <div class="tab-pane fade" id="tab-server" role="tabpanel">
                            <p class="text-muted small mb-3">
                                Seleziona i file ZIP già presenti in
                                <code>storage/manual_upload/</code> (caricati via FTP/file manager)
                                per avviarne l'elaborazione senza doverli ricaricare dal browser.
                            </p>
                            <div id="ade-server-files-list">
                                <div class="text-muted small">Caricamento lista file…</div>
                            </div>
                            <div class="mt-2 d-flex gap-2 flex-wrap">
                                <button id="ade-server-select-all" class="btn btn-sm btn-outline-secondary" type="button" style="display:none!important">
                                    Seleziona tutti
                                </button>
                                <button id="ade-server-submit" class="btn btn-primary btn-sm" type="button" disabled style="display:none!important">
                                    <i class="bi bi-play-fill me-1"></i>Importa selezionati
                                </button>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="tab-sql" role="tabpanel">
                            <p class="text-muted small mb-3">
                                Carica un file <code>.sql</code> già pre-elaborato, oppure selezionalo da
                                <code>storage/manual_upload/</code>. Il worker esegue in background gli statement
                                del file e aggiorna il log live come per gli import ZIP.
                            </p>
                            <div class="alert alert-light border small mb-3">
                                Limite upload PHP corrente: <strong><?= analyticspro_h($sqlUploadMax) ?></strong> per file,
                                <strong><?= analyticspro_h($sqlPostMax) ?></strong> per richiesta.
                                Per file SQL molto grandi usa <code>storage/manual_upload/</code> via FTP/file manager.
                            </div>
                            <input id="ade-sql-files" type="file" class="form-control mb-2" accept=".sql">
                            <button id="ade-sql-submit" class="btn btn-primary mt-2" type="button" disabled>
                                <i class="bi bi-cloud-upload me-1"></i>Importa SQL
                            </button>
                            <hr>
                            <p class="text-muted small mb-3">
                                In alternativa seleziona uno o più file <code>.sql</code> già presenti in
                                <code>storage/manual_upload/</code>.
                            </p>
                            <div id="ade-server-sql-files-list">
                                <div class="text-muted small">Caricamento lista file…</div>
                            </div>
                            <div class="mt-2 d-flex gap-2 flex-wrap">
                                <button id="ade-server-sql-select-all" class="btn btn-sm btn-outline-secondary" type="button" style="display:none!important">
                                    Seleziona tutti
                                </button>
                                <button id="ade-server-sql-submit" class="btn btn-primary btn-sm" type="button" disabled style="display:none!important">
                                    <i class="bi bi-play-fill me-1"></i>Importa SQL selezionati
                                </button>
                            </div>
                        </div>
                    </div>

                    <div id="ade-jobs" class="mt-3"></div>
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
                                        <th>Comuni / Particelle</th>
                                        <th>Avviato</th>
                                        <th></th>
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
                                            $isSqlJob = strtolower((string) pathinfo((string) $job['zip_filename'], PATHINFO_EXTENSION)) === 'sql';
                                            ?>
                                            <span class="badge bg-<?= analyticspro_h($sc) ?>"><?= analyticspro_h((string) $job['status']) ?></span>
                                        </td>
                                        <td class="small">
                                            <?php if ($isSqlJob): ?>
                                                <?= analyticspro_h((string) $job['processed_comuni']) ?>/<?= analyticspro_h((string) $job['total_comuni']) ?> INSERT comuni<br>
                                                <?= analyticspro_h((string) $job['processed_particelle']) ?>/<?= analyticspro_h((string) $job['total_particelle']) ?> INSERT particelle
                                            <?php else: ?>
                                                <?= analyticspro_h((string) $job['processed_comuni']) ?>/<?= analyticspro_h((string) $job['total_comuni']) ?> comuni<br>
                                                <?= analyticspro_h((string) $job['processed_particelle']) ?>/<?= analyticspro_h((string) $job['total_particelle']) ?> particelle
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-muted small"><?= analyticspro_h((string) $job['created_at']) ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-secondary ade-open-log-btn"
                                                    data-job-id="<?= analyticspro_h((string) $job['id']) ?>"
                                                    data-job-label="<?= analyticspro_h(strtoupper((string) $job['provincia_sigla']) . ' · ' . (string) $job['zip_filename']) ?>"
                                                    title="Vedi log">
                                                <i class="bi bi-terminal"></i>
                                            </button>
                                        </td>
                                    </tr>
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

<!-- Modal log live -->
<div class="modal fade" id="ade-log-modal" tabindex="-1" aria-labelledby="ade-log-modal-label" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content bg-dark text-light">
            <div class="modal-header border-secondary">
                <div>
                    <h5 class="modal-title" id="ade-log-modal-label">Log job ADE</h5>
                    <div id="ade-log-modal-status" class="small mt-1"></div>
                </div>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <div class="modal-body p-0">
                <div id="ade-log-modal-body" class="font-monospace p-3" style="font-size:.78rem;min-height:300px;max-height:60vh;overflow-y:auto;background:#1e1e1e;"></div>
            </div>
            <div class="modal-footer border-secondary">
                <span id="ade-log-modal-footer" class="text-muted small me-auto"></span>
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Chiudi</button>
            </div>
        </div>
    </div>
</div>
<?php analyticspro_render_footer(true); ?>
