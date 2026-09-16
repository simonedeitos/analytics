<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/ui/helpers.php';
require_once __DIR__ . '/includes/duplicate_merge_service.php';
require_once __DIR__ . '/includes/maintenance_service.php';

analyticspro_require_auth();
$user = analyticspro_current_user();
if (($user['role'] ?? '') === 'subuser' && !empty($user['must_change_password'])) {
    analyticspro_redirect('change_password.php');
}
if (!analyticspro_maintenance_access_allowed()) {
    analyticspro_set_flash('danger', 'Accesso consentito solo all\'utente principale.');
    analyticspro_redirect('dashboard.php');
}

$downloadLog = trim((string) analyticspro_get('download_log', ''));
if ($downloadLog !== '') {
    try {
        $logPath = analyticspro_maintenance_resolve_log_download($downloadLog);
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . basename($logPath) . '"');
        header('Content-Length: ' . (string) filesize($logPath));
        readfile($logPath);
        exit;
    } catch (Throwable $exception) {
        analyticspro_set_flash('danger', $exception->getMessage());
        analyticspro_redirect('manutenzione.php');
    }
}

$selectedTenantRaw = analyticspro_is_admin() ? (string) analyticspro_get('tenant_id', 'all') : (string) ($user['id'] ?? '');
$selectedTenantId = analyticspro_maintenance_resolve_requested_tenant_id($selectedTenantRaw);
$tenants = analyticspro_is_admin() ? analyticspro_fetch_tenants() : [$user];
$migrations = analyticspro_maintenance_get_migration_statuses();
$migrationByNumber = [];
foreach ($migrations as $migration) {
    $migrationByNumber[(int) $migration['number']] = $migration;
}
$ownershipReady = (($migrationByNumber[15]['status'] ?? 'unknown') === 'applied');
$duplicateClusterCount = $ownershipReady ? analyticspro_duplicate_merge_count_clusters($selectedTenantId) : null;
$nextStep = 'Nessuna azione bloccante rilevata.';
if (($migrationByNumber[14]['status'] ?? 'unknown') !== 'applied' || ($migrationByNumber[15]['status'] ?? 'unknown') !== 'applied') {
    $nextStep = 'Esegui prima le migrazioni 014 e 015.';
} elseif (($duplicateClusterCount ?? 0) > 0) {
    $nextStep = 'Completa il merge duplicati (dry-run, poi apply) prima della migrazione 016.';
} elseif (($migrationByNumber[16]['status'] ?? 'unknown') !== 'applied') {
    $nextStep = 'Esegui la migrazione 016 dopo aver verificato che non restino duplicati.';
}

analyticspro_render_header('Manutenzione database', ['app_assets' => true]);
?>
<div id="analyticspro-maintenance-app"
     data-selected-tenant="<?= analyticspro_h($selectedTenantRaw) ?>"
     data-scan-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/maintenance_duplicate_scan.php')) ?>"
     data-apply-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/maintenance_duplicate_apply.php')) ?>"
     data-migrations-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/maintenance_migrations.php')) ?>"
     data-run-migration-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/maintenance_run_migration.php')) ?>"
     data-datatables-language-url="<?= analyticspro_h(analyticspro_asset_url('assets/i18n/datatables-it-IT.json')) ?>"
     data-batch-size="10">

    <?= analyticspro_ui_page_header(
        'Manutenzione database',
        'Esegui migrazioni SQL e merge dei duplicati direttamente dal browser, riusando la stessa logica condivisa usata dagli strumenti esistenti.',
        '',
        ['eyebrow' => 'Operations']
    ) ?>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="alert alert-danger mb-3">
                <strong>Backup obbligatorio.</strong> Prima di eseguire merge o migrazioni distruttive crea un dump completo del database. Il merge elimina righe da <code>properties</code> ed è irreversibile.
            </div>
            <div class="row g-3 align-items-stretch">
                <div class="col-lg-7">
                    <h2 class="h5">Sequenza corretta</h2>
                    <ol class="small mb-0">
                        <li>Migrazioni <strong>014</strong> e <strong>015</strong></li>
                        <li>Merge duplicati: <strong>dry-run</strong>, poi <strong>apply</strong></li>
                        <li>Migrazione <strong>016</strong></li>
                    </ol>
                </div>
                <div class="col-lg-5">
                    <div class="border rounded-4 p-3 h-100 bg-light-subtle">
                        <div class="small text-uppercase text-muted fw-semibold mb-2">Prossimo passo consigliato</div>
                        <div id="maintenance-next-step" class="fw-semibold mb-2"><?= analyticspro_h($nextStep) ?></div>
                        <div class="small text-muted">
                            Cluster duplicati rilevati per il tenant corrente: <strong id="maintenance-initial-duplicate-count"><?= $duplicateClusterCount === null ? '—' : (string) $duplicateClusterCount ?></strong>
                        </div>
                    </div>
                </div>
            </div>
            <div id="maintenance-global-feedback" class="alert d-none mt-3 mb-0" role="status" aria-live="polite" aria-atomic="true"></div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h2 class="h5 mb-1">Sezione A — Stato delle migrazioni</h2>
                    <p class="text-muted small mb-0">Le migrazioni vengono lette dinamicamente da <code>analyticspro/sql/migrations/</code>. Lo stato è determinato via <code>INFORMATION_SCHEMA</code> quando possibile.</p>
                </div>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="refresh-migrations-btn">
                    <i class="bi bi-arrow-clockwise me-1"></i>Aggiorna stato
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" id="maintenance-migrations-table">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>File</th>
                            <th>Descrizione</th>
                            <th>Stato</th>
                            <th class="text-end">Azione</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($migrations as $migration): ?>
                        <tr data-migration-number="<?= (int) $migration['number'] ?>" data-filename="<?= analyticspro_h((string) $migration['filename']) ?>">
                            <td class="fw-semibold"><?= str_pad((string) ((int) $migration['number']), 3, '0', STR_PAD_LEFT) ?></td>
                            <td><code><?= analyticspro_h((string) $migration['filename']) ?></code></td>
                            <td class="small"><?= analyticspro_h((string) ($migration['description'] ?: '—')) ?></td>
                            <td class="migration-status-cell">
                                <span class="badge <?= ($migration['status'] ?? '') === 'applied' ? 'bg-success' : (($migration['status'] ?? '') === 'not_applied' ? 'bg-warning text-dark' : 'bg-secondary') ?>">
                                    <?= analyticspro_h((string) $migration['status_label']) ?>
                                </span>
                            </td>
                            <td class="text-end migration-action-cell">
                                <?php if (($migration['status'] ?? '') !== 'applied'): ?>
                                    <button type="button" class="btn btn-sm btn-outline-primary run-migration-btn" data-filename="<?= analyticspro_h((string) $migration['filename']) ?>">
                                        <?= ($migration['status'] ?? '') === 'unknown' ? 'Esegui comunque' : 'Esegui' ?>
                                    </button>
                                <?php else: ?>
                                    <span class="text-muted small">Già applicata</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4" id="duplicates-section">
        <div class="card-body">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                <div>
                    <h2 class="h5 mb-1">Sezione B — Merge dei duplicati</h2>
                    <p class="text-muted small mb-0">Il dry-run mostra i cluster rilevati senza scrivere sul database. L'apply elabora i cluster a batch via AJAX con log progressivo.</p>
                </div>
                <div class="d-flex gap-2 flex-wrap align-items-center">
                    <label for="maintenance-tenant-select" class="form-label small mb-0">Tenant</label>
                    <select id="maintenance-tenant-select" class="form-select form-select-sm" style="min-width:240px">
                        <?php if (analyticspro_is_admin()): ?>
                            <option value="all" <?= $selectedTenantRaw === 'all' ? 'selected' : '' ?>>Tutti i tenant</option>
                        <?php endif; ?>
                        <?php foreach ($tenants as $tenant): ?>
                            <?php $tenantIdValue = (string) ($tenant['id'] ?? ''); ?>
                            <option value="<?= analyticspro_h($tenantIdValue) ?>" <?= $selectedTenantRaw === $tenantIdValue ? 'selected' : '' ?>><?= analyticspro_h(analyticspro_full_name($tenant)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn btn-outline-primary btn-sm" id="duplicate-scan-btn" <?= $ownershipReady ? '' : 'disabled' ?>>Analizza duplicati (dry-run)</button>
                    <button type="button" class="btn btn-danger btn-sm" id="duplicate-apply-btn" disabled>Applica merge</button>
                </div>
            </div>
            <div id="duplicate-prereq-alert" class="alert <?= $ownershipReady ? 'd-none' : 'alert-warning' ?> small">
                Il merge è disponibile solo dopo la migrazione <strong>015</strong>, perché quota e titolarità devono essere già presenti su <code>property_owners</code>.
            </div>
            <div id="duplicate-feedback" class="alert d-none small" role="status" aria-live="polite" aria-atomic="true"></div>
            <div class="row g-3 mb-3" id="duplicate-summary-row">
                <div class="col-md-4"><div class="border rounded-4 p-3 h-100"><div class="small text-uppercase text-muted fw-semibold mb-1">Cluster</div><div class="fs-4 fw-bold" id="duplicate-summary-clusters">0</div></div></div>
                <div class="col-md-4"><div class="border rounded-4 p-3 h-100"><div class="small text-uppercase text-muted fw-semibold mb-1">Properties coinvolte</div><div class="fs-4 fw-bold" id="duplicate-summary-properties">0</div></div></div>
                <div class="col-md-4"><div class="border rounded-4 p-3 h-100"><div class="small text-uppercase text-muted fw-semibold mb-1">Owners coinvolti</div><div class="fs-4 fw-bold" id="duplicate-summary-owners">0</div></div></div>
            </div>
            <div id="duplicate-progress-card" class="border rounded-4 p-3 mb-3 d-none" role="status" aria-live="polite" aria-atomic="false">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                    <div class="fw-semibold">Avanzamento merge</div>
                    <a id="duplicate-log-download" class="small d-none" href="#">Scarica log</a>
                </div>
                <div class="progress mb-2" style="height: 10px;">
                    <div id="duplicate-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated" style="width: 0%;"></div>
                </div>
                <div id="duplicate-progress-text" class="small text-muted" role="status" aria-live="polite" aria-atomic="true">In attesa di esecuzione.</div>
                <pre id="duplicate-progress-log" class="bg-dark text-light small p-3 rounded-4 mt-3 mb-0" style="max-height: 240px; overflow: auto; white-space: pre-wrap;" aria-live="polite"></pre>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-striped table-hover w-100 align-middle" id="duplicate-clusters-table">
                    <thead>
                        <tr>
                            <th>Comune</th>
                            <th>Foglio</th>
                            <th>Particella</th>
                            <th>Subalterno</th>
                            <th>Keeper</th>
                            <th>Assorbiti</th>
                            <th>Owners</th>
                            <th>Note</th>
                            <th>Assegnazioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td colspan="9" class="text-center text-muted py-3 small">Esegui il dry-run per vedere i cluster rilevati.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="modal fade" id="duplicate-apply-confirm-modal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Conferma merge duplicati</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger small">
                        L'operazione elimina righe da <code>properties</code>, sposta relazioni e non è reversibile. Verifica di avere un backup aggiornato prima di procedere.
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="duplicate-confirm-backup">
                        <label class="form-check-label" for="duplicate-confirm-backup">Confermo di aver eseguito un backup e di voler procedere.</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Annulla</button>
                    <button type="button" class="btn btn-danger btn-sm" id="duplicate-confirm-apply-btn" disabled>Avvia merge</button>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
window.addEventListener('load', function () {
    var root = document.getElementById('analyticspro-maintenance-app');
    if (!root) return;

    var state = {
        csrfToken: document.querySelector('meta[name="csrf-token"]') ? document.querySelector('meta[name="csrf-token"]').content : '',
        selectedTenant: root.dataset.selectedTenant || 'all',
        scanEndpoint: root.dataset.scanEndpoint || '',
        applyEndpoint: root.dataset.applyEndpoint || '',
        migrationsEndpoint: root.dataset.migrationsEndpoint || '',
        runMigrationEndpoint: root.dataset.runMigrationEndpoint || '',
        dataTablesLanguageUrl: root.dataset.datatablesLanguageUrl || '',
        batchSize: Math.max(1, parseInt(root.dataset.batchSize || '10', 10) || 10),
        ownershipReady: <?= $ownershipReady ? 'true' : 'false' ?>,
        initialDuplicateCount: <?= $duplicateClusterCount === null ? 'null' : (int) $duplicateClusterCount ?>,
        migrations: <?= json_encode($migrations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
        lastScan: null,
        dataTable: null,
        applyModal: bootstrap.Modal.getOrCreateInstance(document.getElementById('duplicate-apply-confirm-modal')),
        logFile: '',
        applyRunning: false
    };

    function selectedTenantValue() {
        var select = document.getElementById('maintenance-tenant-select');
        return select ? String(select.value || 'all') : String(state.selectedTenant || 'all');
    }

    function api(url, options) {
        options = Object.assign({ credentials: 'same-origin' }, options || {});
        return fetch(url, options).then(function (response) {
            return response.json().catch(function () {
                throw new Error('Risposta non valida dal server.');
            }).then(function (payload) {
                if (!response.ok || !payload.ok) {
                    var error = new Error(payload && payload.error ? payload.error : 'Operazione non riuscita.');
                    error.payload = payload || {};
                    throw error;
                }
                return payload;
            });
        });
    }

    function setAlert(id, type, message) {
        var el = document.getElementById(id);
        if (!el) return;
        if (!message) {
            el.className = 'alert d-none';
            el.textContent = '';
            return;
        }
        el.className = 'alert alert-' + (type || 'info');
        el.textContent = message;
    }

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function appendProgressLine(message) {
        var log = document.getElementById('duplicate-progress-log');
        if (!log) return;
        log.textContent += (log.textContent ? '\n' : '') + message;
        log.scrollTop = log.scrollHeight;
    }

    function updateApplyButtonState() {
        var button = document.getElementById('duplicate-apply-btn');
        if (!button) return;
        var scanValid = state.lastScan && String(state.lastScan.tenant_id || 'all') === (selectedTenantValue() === 'all' ? 'all' : String(selectedTenantValue()));
        var clustersValid = !!(state.lastScan && Array.isArray(state.lastScan.clusters) && state.lastScan.clusters.length > 0 && state.lastScan.clusters.every(function (cluster) {
            return Array.isArray(cluster.property_ids) && cluster.property_ids.length >= 2;
        }));
        button.disabled = !state.ownershipReady || state.applyRunning || !scanValid || !clustersValid;
    }

    function renderNextStep() {
        var migrationsByNumber = {};
        (state.migrations || []).forEach(function (migration) {
            migrationsByNumber[String(migration.number)] = migration;
        });
        var message = 'Nessuna azione bloccante rilevata.';
        if (!migrationsByNumber['14'] || migrationsByNumber['14'].status !== 'applied' || !migrationsByNumber['15'] || migrationsByNumber['15'].status !== 'applied') {
            message = 'Esegui prima le migrazioni 014 e 015.';
        } else if (state.lastScan && state.lastScan.total_clusters > 0) {
            message = 'Completa il merge duplicati (dry-run, poi apply) prima della migrazione 016.';
        } else if (state.initialDuplicateCount === null) {
            message = 'Esegui un dry-run del merge duplicati prima della migrazione 016.';
        } else if (state.initialDuplicateCount > 0) {
            message = 'Sono presenti duplicati: esegui la scansione dry-run e poi il merge.';
        } else if (!migrationsByNumber['16'] || migrationsByNumber['16'].status !== 'applied') {
            message = 'Esegui la migrazione 016 dopo aver confermato l’assenza di duplicati.';
        }
        var nextStep = document.getElementById('maintenance-next-step');
        if (nextStep) nextStep.textContent = message;
        var countEl = document.getElementById('maintenance-initial-duplicate-count');
        if (countEl) {
            if (state.lastScan) {
                countEl.textContent = String(state.lastScan.total_clusters || 0);
            } else {
                countEl.textContent = state.initialDuplicateCount === null ? '—' : String(state.initialDuplicateCount);
            }
        }
    }

    function renderMigrationTable() {
        var tbody = document.querySelector('#maintenance-migrations-table tbody');
        if (!tbody) return;
        tbody.innerHTML = (state.migrations || []).map(function (migration) {
            var statusClass = migration.status === 'applied' ? 'bg-success' : (migration.status === 'not_applied' ? 'bg-warning text-dark' : 'bg-secondary');
            var actionHtml = migration.status === 'applied'
                ? '<span class="text-muted small">Già applicata</span>'
                : '<button type="button" class="btn btn-sm btn-outline-primary run-migration-btn" data-filename="' + String(migration.filename).replace(/"/g, '&quot;') + '">' + (migration.status === 'unknown' ? 'Esegui comunque' : 'Esegui') + '</button>';
            return '<tr data-migration-number="' + migration.number + '" data-filename="' + escapeHtml(migration.filename) + '">' +
                '<td class="fw-semibold">' + String(migration.number).padStart(3, '0') + '</td>' +
                '<td><code>' + escapeHtml(migration.filename) + '</code></td>' +
                '<td class="small">' + escapeHtml(migration.description || '—') + '</td>' +
                '<td><span class="badge ' + statusClass + '">' + escapeHtml(migration.status_label) + '</span></td>' +
                '<td class="text-end">' + actionHtml + '</td>' +
                '</tr>';
        }).join('');
        state.ownershipReady = !!(state.migrations || []).find(function (migration) { return Number(migration.number) === 15 && migration.status === 'applied'; });
        var prereq = document.getElementById('duplicate-prereq-alert');
        if (prereq) prereq.className = state.ownershipReady ? 'alert d-none small' : 'alert alert-warning small';
        var scanBtn = document.getElementById('duplicate-scan-btn');
        if (scanBtn) scanBtn.disabled = !state.ownershipReady || state.applyRunning;
        updateApplyButtonState();
        renderNextStep();
    }

    function renderScanSummary(scan) {
        document.getElementById('duplicate-summary-clusters').textContent = String(scan && scan.summary ? scan.summary.clusters || 0 : 0);
        document.getElementById('duplicate-summary-properties').textContent = String(scan && scan.summary ? scan.summary.properties || 0 : 0);
        document.getElementById('duplicate-summary-owners').textContent = String(scan && scan.summary ? scan.summary.owners || 0 : 0);
    }

    function renderClusters(scan) {
        renderScanSummary(scan || { summary: {} });
        if (state.dataTable) {
            state.dataTable.destroy();
            state.dataTable = null;
        }
        var table = $('#duplicate-clusters-table');
        var rows = scan && Array.isArray(scan.clusters) ? scan.clusters : [];
        if (!rows.length) {
            table.find('tbody').html('<tr><td colspan="9" class="text-center text-muted py-3 small">Nessun cluster trovato per il filtro selezionato.</td></tr>');
            updateApplyButtonState();
            return;
        }
        state.dataTable = table.DataTable({
            destroy: true,
            data: rows,
            columns: [
                { title: 'Comune', data: 'comune', render: function (value) { return escapeHtml(value); } },
                { title: 'Foglio', data: 'foglio', render: function (value) { return escapeHtml(value); } },
                { title: 'Particella', data: 'particella', render: function (value) { return escapeHtml(value); } },
                { title: 'Subalterno', data: 'subalterno', render: function (value) { return escapeHtml(value); } },
                { title: 'Keeper', data: 'keeper_id' },
                { title: 'Assorbiti', data: 'absorbed_ids', render: function (value) { return Array.isArray(value) ? value.join(', ') : ''; } },
                { title: 'Owners', data: 'owners_count' },
                { title: 'Note', data: 'notes_count' },
                { title: 'Assegnazioni', data: 'assignments_count' }
            ],
            pageLength: 25,
            order: [],
            language: state.dataTablesLanguageUrl ? { url: state.dataTablesLanguageUrl } : undefined
        });
        updateApplyButtonState();
    }

    function loadMigrationStatuses() {
        return api(state.migrationsEndpoint).then(function (payload) {
            state.migrations = payload.migrations || [];
            renderMigrationTable();
        }).catch(function (error) {
            setAlert('maintenance-global-feedback', 'warning', error.message);
        });
    }

    function analyzeDuplicates(options) {
        options = options || {};
        setAlert('duplicate-feedback', '', '');
        setAlert('maintenance-global-feedback', '', '');
        return api(state.scanEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: state.csrfToken,
                tenant_id: selectedTenantValue() === 'all' ? null : selectedTenantValue(),
                offset: 0,
                limit: 0
            })
        }).then(function (payload) {
            state.lastScan = payload;
            state.initialDuplicateCount = payload.total_clusters;
            renderClusters(payload);
            renderNextStep();
            setAlert('duplicate-feedback', payload.total_clusters > 0 ? 'info' : 'success', payload.total_clusters > 0
                ? 'Dry-run completato: trovati ' + payload.total_clusters + ' cluster duplicati.'
                : 'Dry-run completato: nessun cluster duplicato trovato.');
            updateApplyButtonState();
        }).catch(function (error) {
            if (options.afterApply) {
                appendProgressLine('Avviso aggiornamento post-merge: ' + error.message);
                setAlert('maintenance-global-feedback', 'warning', 'Merge completato, ma il refresh automatico dello stato non è riuscito: ' + error.message);
                return;
            }
            setAlert('duplicate-feedback', 'danger', error.message);
        });
    }

    function setProgress(completed, total) {
        var bar = document.getElementById('duplicate-progress-bar');
        var text = document.getElementById('duplicate-progress-text');
        var ratio = total > 0 ? Math.round((completed / total) * 100) : 0;
        if (bar) bar.style.width = ratio + '%';
        if (text) text.textContent = 'Cluster completati: ' + completed + ' / ' + total;
    }

    function setLogLink(url) {
        var link = document.getElementById('duplicate-log-download');
        if (!link) return;
        if (!url) {
            link.href = '#';
            link.classList.add('d-none');
            return;
        }
        link.href = url;
        link.classList.remove('d-none');
    }

    function runApplyBatch(completed) {
        var total = state.lastScan && state.lastScan.total_clusters ? state.lastScan.total_clusters : 0;
        if (!state.lastScan || !Array.isArray(state.lastScan.clusters)) {
            throw new Error('Esegui prima il dry-run.');
        }
        if (completed >= total) {
            return Promise.resolve(completed);
        }
        var slice = state.lastScan.clusters.slice(completed, completed + state.batchSize).map(function (cluster) {
            return { property_ids: cluster.property_ids || [] };
        });
        return api(state.applyEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: state.csrfToken,
                tenant_id: selectedTenantValue() === 'all' ? null : selectedTenantValue(),
                clusters: slice,
                completed_before: completed,
                total_clusters: total,
                summary: state.lastScan.summary || {},
                log_file: state.logFile || null
            })
        }).then(function (payload) {
            state.logFile = payload.log_file || state.logFile;
            setLogLink(payload.log_url || '');
            (payload.results || []).forEach(function (result) {
                appendProgressLine('Cluster ' + result.cluster_number + ': keeper #' + result.keeper_id + ', assorbiti [' + (result.absorbed_ids || []).join(', ') + ']');
            });
            setProgress(payload.completed_clusters || completed, total);
            return runApplyBatch(payload.completed_clusters || completed);
        }).catch(function (error) {
            var payload = error.payload || {};
            var processed = completed + (payload.processed_clusters || 0);
            setProgress(processed, total);
            setLogLink(payload.log_url || '');
            appendProgressLine('ERRORE: ' + error.message);
            throw new Error(error.message + ' Cluster completati con successo: ' + processed + ' di ' + total + '.');
        });
    }

    function startApply() {
        if (state.applyRunning) {
            return;
        }
        if (!state.lastScan || !state.lastScan.total_clusters) {
            setAlert('duplicate-feedback', 'warning', 'Esegui prima il dry-run e verifica i cluster da fondere.');
            return;
        }
        if (!Array.isArray(state.lastScan.clusters) || state.lastScan.clusters.some(function (cluster) {
            return !Array.isArray(cluster.property_ids) || cluster.property_ids.length < 2;
        })) {
            setAlert('duplicate-feedback', 'danger', 'Dry-run non valido: alcuni cluster non espongono abbastanza property_id per l'apply. Riesegui l'analisi.');
            return;
        }
        state.applyRunning = true;
        document.getElementById('duplicate-confirm-apply-btn').disabled = true;
        document.getElementById('duplicate-progress-card').classList.remove('d-none');
        document.getElementById('duplicate-progress-log').textContent = '';
        state.logFile = '';
        setLogLink('');
        setProgress(0, state.lastScan.total_clusters);
        updateApplyButtonState();
        var scanBtn = document.getElementById('duplicate-scan-btn');
        if (scanBtn) scanBtn.disabled = true;
        runApplyBatch(0).then(function () {
            appendProgressLine('Merge completato senza errori.');
            setAlert('duplicate-feedback', 'success', 'Merge completato correttamente.');
            return loadMigrationStatuses();
        }).then(function () {
            return analyzeDuplicates({ afterApply: true });
        }).catch(function (error) {
            setAlert('duplicate-feedback', 'danger', error.message);
        }).finally(function () {
            state.applyRunning = false;
            state.applyModal.hide();
            document.getElementById('duplicate-confirm-backup').checked = false;
            document.getElementById('duplicate-confirm-apply-btn').disabled = true;
            if (scanBtn) scanBtn.disabled = !state.ownershipReady;
            updateApplyButtonState();
        });
    }

    document.getElementById('refresh-migrations-btn').addEventListener('click', function () {
        loadMigrationStatuses();
    });
    document.getElementById('duplicate-scan-btn').addEventListener('click', function () {
        analyzeDuplicates();
    });
    document.getElementById('maintenance-tenant-select').addEventListener('change', function () {
        state.selectedTenant = selectedTenantValue();
        state.lastScan = null;
        renderClusters({ clusters: [], summary: {} });
        renderNextStep();
        setAlert('duplicate-feedback', 'info', 'Tenant cambiato: esegui di nuovo il dry-run per aggiornare l’anteprima.');
        updateApplyButtonState();
    });
    document.getElementById('duplicate-apply-btn').addEventListener('click', function () {
        if (this.disabled) return;
        state.applyModal.show();
    });
    document.getElementById('duplicate-confirm-backup').addEventListener('change', function () {
        document.getElementById('duplicate-confirm-apply-btn').disabled = !this.checked;
    });
    document.getElementById('duplicate-confirm-apply-btn').addEventListener('click', function () {
        startApply();
    });
    document.addEventListener('click', function (event) {
        var button = event.target.closest('.run-migration-btn');
        if (!button) return;
        var filename = button.getAttribute('data-filename') || '';
        button.disabled = true;
        api(state.runMigrationEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: state.csrfToken, filename: filename })
        }).then(function (payload) {
            setAlert('maintenance-global-feedback', 'success', payload.message || 'Migrazione eseguita correttamente.');
            return loadMigrationStatuses();
        }).catch(function (error) {
            if (error.payload && error.payload.blocked_by_duplicates) {
                setAlert('maintenance-global-feedback', 'warning', 'La migrazione 016 è bloccata da duplicati esistenti: completa prima la Sezione B (dry-run + apply).');
                var section = document.getElementById('duplicates-section');
                if (section) section.scrollIntoView({ behavior: 'smooth', block: 'start' });
            } else {
                setAlert('maintenance-global-feedback', 'danger', error.message);
            }
        }).finally(function () {
            button.disabled = false;
        });
    });

    renderMigrationTable();
    renderClusters({ clusters: [], summary: { clusters: 0, properties: 0, owners: 0 } });
    renderNextStep();
});
</script>
<?php analyticspro_render_footer(true); ?>
