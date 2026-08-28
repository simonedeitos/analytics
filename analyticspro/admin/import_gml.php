<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require __DIR__ . '/_admin_check.php';
require_once ANALYTICSPRO_ROOT . '/includes/gml_catalog.php';

$uploadMax  = trim((string) ini_get('upload_max_filesize')) ?: 'n/d';
$postMax    = trim((string) ini_get('post_max_size')) ?: 'n/d';
$maxFiles   = trim((string) ini_get('max_file_uploads')) ?: 'n/d';

/** Converts a PHP ini size string (e.g. "8M", "2G", "512K") to bytes. */
function analyticspro_ini_bytes(string $iniKey): int
{
    $raw = trim((string) ini_get($iniKey));
    if ($raw === '') {
        return 8 * 1048576;
    }
    $num    = (int) $raw;
    $suffix = strtolower(substr($raw, -1));
    return match ($suffix) {
        'g'     => $num * 1073741824,
        'm'     => $num * 1048576,
        'k'     => $num * 1024,
        default => $num,
    };
}

analyticspro_render_header('Import GML', ['app_assets' => true]);
require __DIR__ . '/_admin_subnav.php';
?>
<div id="analyticspro-app"
     data-gml-upload-endpoint="<?= analyticspro_h(analyticspro_base_url('api/admin/gml_upload.php')) ?>"
     data-gml-catalog-endpoint="<?= analyticspro_h(analyticspro_base_url('api/admin/gml_catalog.php')) ?>"
     data-gml-inspect-endpoint="<?= analyticspro_h(analyticspro_base_url('api/admin/gml_inspect.php')) ?>"
     data-gml-index-endpoint="<?= analyticspro_h(analyticspro_base_url('api/admin/gml_index_build.php')) ?>">

    <h1 class="h3 mb-4"><i class="bi bi-map me-2"></i>Import GML ADE locale</h1>

    <div class="row g-4">
        <!-- Colonna sinistra: upload -->
        <div class="col-lg-5">
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body">
                    <h2 class="h5 mb-3">Carica file GML</h2>

                    <div class="alert alert-light border small mb-3">
                        Limiti PHP: <strong><?= analyticspro_h($uploadMax) ?></strong> per file
                        · <strong><?= analyticspro_h($postMax) ?></strong> per richiesta
                        · max <strong><?= analyticspro_h($maxFiles) ?></strong> file per upload
                    </div>

                    <!-- Zona drag &amp; drop -->
                    <div id="gml-drop-zone"
                         class="border border-2 border-dashed rounded-3 p-4 text-center mb-3"
                         style="border-color:#0d6efd!important;cursor:pointer;min-height:120px">
                        <i class="bi bi-cloud-upload fs-2 text-primary"></i>
                        <div class="mt-2 text-muted small">
                            Trascina qui le <strong>cartelle</strong> o i file GML/ZIP<br>
                            (anche più cartelle contemporaneamente, struttura annidata)
                        </div>
                        <div class="mt-2 d-flex gap-2 justify-content-center align-items-center">
                            <span class="badge bg-secondary" id="gml-drop-count">0 file selezionati</span>
                            <button id="gml-drop-clear" class="btn btn-xs btn-outline-secondary" type="button" style="display:none">
                                <i class="bi bi-x-circle me-1"></i>Svuota selezione
                            </button>
                        </div>
                    </div>

                    <!-- Fallback input file -->
                    <div class="d-flex gap-2 flex-wrap mb-3">
                        <label class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-folder me-1"></i>Cartella
                            <input id="gml-input-dir" type="file" class="d-none" webkitdirectory multiple>
                        </label>
                        <label class="btn btn-outline-secondary btn-sm">
                            <i class="bi bi-file-earmark me-1"></i>File GML/ZIP
                            <input id="gml-input-files" type="file" class="d-none" multiple accept=".gml,.zip">
                        </label>
                    </div>

                    <!-- Barra avanzamento -->
                    <div id="gml-progress-wrap" style="display:none" class="mb-3">
                        <div class="d-flex justify-content-between small mb-1">
                            <span id="gml-progress-label">Caricamento…</span>
                            <span id="gml-progress-pct">0%</span>
                        </div>
                        <div class="progress" style="height:8px">
                            <div id="gml-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated"
                                 style="width:0"></div>
                        </div>
                        <div class="text-muted small mt-1" id="gml-progress-detail"></div>
                    </div>

                    <button id="gml-upload-btn" class="btn btn-primary" type="button" disabled>
                        <i class="bi bi-cloud-upload me-1"></i>Carica e rigenera catalogo
                    </button>

                    <div id="gml-upload-result" class="mt-3"></div>
                </div>
            </div>

            <!-- Strumento diagnostica -->
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h2 class="h5 mb-3"><i class="bi bi-search me-1"></i>Diagnostica comune</h2>
                    <p class="text-muted small mb-2"><i class="bi bi-info-circle me-1"></i>L'analisi può richiedere alcuni minuti sui comuni grandi.</p>
                    <div class="input-group mb-2">
                        <input id="gml-inspect-belfiore" type="text" class="form-control"
                               placeholder="Codice Belfiore (es. B394)" maxlength="4"
                               style="text-transform:uppercase">
                        <button id="gml-inspect-btn" class="btn btn-outline-secondary" type="button">
                            Analizza
                        </button>
                    </div>
                    <div id="gml-inspect-result"></div>

                    <hr>
                    <h2 class="h5 mb-3"><i class="bi bi-database me-1"></i>Costruisci indice particelle</h2>
                    <p class="text-muted small">Indicizza tutti i comuni presenti nel catalogo per abilitare il lookup O(1) durante l'import CSV.
                        L'indicizzazione viene eseguita in background — un log live mostrerà lo stato.</p>
                    <button id="gml-build-index-btn" class="btn btn-warning btn-sm" type="button">
                        <i class="bi bi-gear me-1"></i>Indicizza tutti i comuni
                    </button>
                    <div id="gml-build-index-result" class="mt-2"></div>

                    <!-- Log live indicizzazione -->
                    <div id="gml-index-log-wrap" style="display:none" class="mt-3">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <small class="text-muted fw-semibold" id="gml-index-log-title">Log indicizzazione</small>
                            <span id="gml-index-log-status" class="badge bg-secondary">in coda</span>
                        </div>
                        <div id="gml-index-log-body"
                             class="border rounded p-2 bg-dark text-white small"
                             style="max-height:200px;overflow-y:auto;font-family:monospace;font-size:.78rem;white-space:pre-wrap"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Colonna destra: catalogo -->
        <div class="col-lg-7">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="h5 mb-0">Catalogo comuni</h2>
                        <button id="gml-rebuild-catalog-btn" class="btn btn-outline-primary btn-sm" type="button">
                            <i class="bi bi-arrow-clockwise me-1"></i>Rigenera catalogo
                        </button>
                    </div>

                    <div class="mb-2">
                        <input id="gml-catalog-search" type="text" class="form-control form-control-sm"
                               placeholder="Filtra per Belfiore o nome comune…">
                    </div>

                    <div id="gml-catalog-loading" class="text-muted small">Caricamento catalogo…</div>

                    <div class="table-responsive">
                        <table id="gml-catalog-table" class="table table-sm table-hover align-middle" style="display:none">
                            <thead class="table-light">
                                <tr>
                                    <th>Belfiore</th>
                                    <th>Comune</th>
                                    <th>PLE</th>
                                    <th>MAP</th>
                                    <th>Particelle</th>
                                    <th>Fogli</th>
                                    <th>Aggiornato</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="gml-catalog-body"></tbody>
                        </table>
                    </div>
                    <div id="gml-catalog-empty" class="text-muted small" style="display:none">
                        Nessun comune nel catalogo. Carica dei file GML per iniziare.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<template id="tpl-catalog-row">
    <tr data-belfiore="" data-nome="">
        <td class="fw-semibold td-belfiore"></td>
        <td class="td-nome text-muted small"></td>
        <td class="td-ple text-center"></td>
        <td class="td-map text-center"></td>
        <td class="td-parcels text-end small"></td>
        <td class="td-fogli text-end small"></td>
        <td class="td-mtime small"></td>
        <td>
            <div class="d-flex gap-1">
                <button class="btn btn-xs btn-outline-secondary btn-inspect" title="Diagnostica" type="button">
                    <i class="bi bi-search"></i>
                </button>
                <button class="btn btn-xs btn-outline-warning btn-index" title="Indicizza" type="button">
                    <i class="bi bi-database-add"></i>
                </button>
                <button class="btn btn-xs btn-outline-danger btn-delete" title="Elimina" type="button">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </td>
    </tr>
</template>

<style>
.border-dashed { border-style: dashed !important; }
.btn-xs { padding: .15rem .4rem; font-size: .75rem; line-height: 1.3; }
#gml-drop-zone.drag-over { background: rgba(13,110,253,.07); }
</style>

<script>
(function () {
    'use strict';

    const app          = document.getElementById('analyticspro-app');
    const uploadUrl    = app.dataset.gmlUploadEndpoint;
    const catalogUrl   = app.dataset.gmlCatalogEndpoint;
    const inspectUrl   = app.dataset.gmlInspectEndpoint;
    const indexUrl     = app.dataset.gmlIndexEndpoint;
    const csrfMeta     = document.querySelector('meta[name="csrf-token"]');
    const csrf         = csrfMeta ? csrfMeta.content : '';

    let pendingFiles = [];   // File objects da caricare

    // -------------------------------------------------------------------------
    // Raccolta file (drag&drop + input)
    // -------------------------------------------------------------------------

    const dropZone   = document.getElementById('gml-drop-zone');
    const countBadge = document.getElementById('gml-drop-count');
    const clearBtn   = document.getElementById('gml-drop-clear');
    const uploadBtn  = document.getElementById('gml-upload-btn');
    let totalSkipped = 0;   // contatore cumulativo file scartati per pattern

    function updateDropCount() {
        countBadge.textContent = pendingFiles.length + ' file selezionati';
        uploadBtn.disabled = pendingFiles.length === 0;
        clearBtn.style.display = pendingFiles.length > 0 ? '' : 'none';
    }

    clearBtn.addEventListener('click', () => {
        pendingFiles = [];
        totalSkipped = 0;
        updateDropCount();
        document.getElementById('gml-upload-result').innerHTML = '';
    });

    // Drag & drop con cartelle
    dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
    dropZone.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
    dropZone.addEventListener('drop', e => {
        e.preventDefault();
        dropZone.classList.remove('drag-over');
        const items = e.dataTransfer.items;
        if (!items) return;
        const promises = [];
        for (let i = 0; i < items.length; i++) {
            const entry = items[i].webkitGetAsEntry ? items[i].webkitGetAsEntry() : null;
            if (entry) {
                promises.push(traverseEntry(entry));
            } else if (items[i].kind === 'file') {
                const f = items[i].getAsFile();
                if (f) promises.push(Promise.resolve([f]));
            }
        }
        Promise.all(promises).then(results => {
            const all = results.flat();
            addFiles(all);
        });
    });

    function traverseEntry(entry) {
        if (entry.isFile) {
            return new Promise(resolve => {
                entry.file(f => resolve([f]), () => resolve([]));
            });
        }
        if (entry.isDirectory) {
            return traverseDirectory(entry);
        }
        return Promise.resolve([]);
    }

    function traverseDirectory(dirEntry) {
        return new Promise(resolve => {
            const reader = dirEntry.createReader();
            const allFiles = [];
            function readBatch() {
                reader.readEntries(entries => {
                    if (entries.length === 0) {
                        // Tutti i batch letti
                        Promise.all(entries.map(traverseEntry)).then(r => {
                            allFiles.push(...r.flat());
                            resolve(allFiles);
                        });
                        return;
                    }
                    Promise.all(entries.map(traverseEntry)).then(r => {
                        allFiles.push(...r.flat());
                        readBatch();
                    });
                }, () => resolve(allFiles));
            }
            readBatch();
        });
    }

    const GML_PATTERN = /^([A-Za-z]\d{3})_(.+?)_(ple|map)\.gml$/i;

    function addFiles(files) {
        // Accumula con deduplica per name + size (non sovrascrive)
        const seen = new Set(pendingFiles.map(f => f.name + '|' + f.size));
        let skippedThisDrop = 0;
        for (const f of files) {
            if (/\.(zip)$/i.test(f.name) || GML_PATTERN.test(f.name)) {
                const k = f.name + '|' + f.size;
                if (!seen.has(k)) {
                    pendingFiles.push(f);
                    seen.add(k);
                }
            } else {
                skippedThisDrop++;
            }
        }
        totalSkipped += skippedThisDrop;
        if (totalSkipped > 0) {
            showUploadResult('info', totalSkipped + ' file in totale.');
        }
        updateDropCount();
    }

    document.getElementById('gml-input-dir').addEventListener('change', function () {
        addFiles(Array.from(this.files));
    });
    document.getElementById('gml-input-files').addEventListener('change', function () {
        addFiles(Array.from(this.files));
    });

    // -------------------------------------------------------------------------
    // Upload a batch
    // -------------------------------------------------------------------------

    const progressWrap   = document.getElementById('gml-progress-wrap');
    const progressBar    = document.getElementById('gml-progress-bar');
    const progressLabel  = document.getElementById('gml-progress-label');
    const progressPct    = document.getElementById('gml-progress-pct');
    const progressDetail = document.getElementById('gml-progress-detail');

    const MAX_FILES_PER_BATCH = parseInt('<?= (int)(ini_get('max_file_uploads') ?: 20) ?>') || 20;
    const MB = 1048576;
    // post_max_size as bytes, computed server-side for accuracy (handles M, G, K suffixes)
    const MAX_POST_BYTES = <?= analyticspro_ini_bytes('post_max_size') ?>;
    const MAX_POST_MB = MAX_POST_BYTES / MB;

    uploadBtn.addEventListener('click', async () => {
        if (pendingFiles.length === 0) return;
        uploadBtn.disabled = true;
        progressWrap.style.display = '';

        const saved    = [];
        const replaced = [];
        const skipped  = [];
        let errors     = 0;

        // Suddividi in batch
        const batches = [];
        let batch     = [];
        let batchSize = 0;

        for (const file of pendingFiles) {
            const addSize = batch.length >= MAX_FILES_PER_BATCH || batchSize + file.size > MAX_POST_BYTES * 0.9;
            if (addSize && batch.length > 0) {
                batches.push(batch);
                batch = [];
                batchSize = 0;
            }
            batch.push(file);
            batchSize += file.size;
        }
        if (batch.length > 0) batches.push(batch);

        let done = 0;
        const total = pendingFiles.length;

        for (let bi = 0; bi < batches.length; bi++) {
            const b = batches[bi];
            const fd = new FormData();
            fd.append('csrf_token', csrf);
            for (const f of b) {
                fd.append('files[]', f, f.name);
                done++;
            }
            progressLabel.textContent  = 'Batch ' + (bi + 1) + '/' + batches.length + ' — ' + b[b.length - 1].name;
            progressDetail.textContent = done + '/' + total + ' file';
            const pct = Math.round(done / total * 100);
            progressBar.style.width    = pct + '%';
            progressPct.textContent    = pct + '%';

            try {
                const res  = await fetch(uploadUrl, { method: 'POST', body: fd });
                const data = await res.json();
                if (data.ok) {
                    saved.push(...(data.saved || []));
                    replaced.push(...(data.replaced || []));
                    skipped.push(...(data.skipped || []));
                } else {
                    errors++;
                    showUploadResult('danger', 'Errore batch ' + (bi + 1) + ': ' + (data.error || 'sconosciuto'));
                }
            } catch (e) {
                errors++;
                showUploadResult('danger', 'Errore di rete nel batch ' + (bi + 1));
            }
        }

        progressBar.classList.remove('progress-bar-animated');

        let msg = '';
        if (saved.length > 0)    msg += saved.length + ' nuovi file salvati. ';
        if (replaced.length > 0) msg += replaced.length + ' file aggiornati. ';
        if (skipped.length > 0)  msg += skipped.length + ' file ignorati. ';
        if (errors > 0)          msg += errors + ' batch con errori.';

        showUploadResult(errors > 0 ? 'warning' : 'success', msg || 'Upload completato.');
        pendingFiles = [];
        totalSkipped = 0;
        updateDropCount();
        loadCatalog();
    });

    function showUploadResult(type, msg) {
        document.getElementById('gml-upload-result').innerHTML =
            '<div class="alert alert-' + type + ' small py-2 mb-0">' + escHtml(msg) + '</div>';
    }

    // -------------------------------------------------------------------------
    // Catalogo
    // -------------------------------------------------------------------------

    function loadCatalog() {
        document.getElementById('gml-catalog-loading').style.display = '';
        document.getElementById('gml-catalog-table').style.display   = 'none';
        document.getElementById('gml-catalog-empty').style.display   = 'none';

        fetch(catalogUrl)
            .then(r => r.json())
            .then(data => {
                document.getElementById('gml-catalog-loading').style.display = 'none';
                if (!data.ok) return;
                renderCatalog(data.catalog || []);
            })
            .catch(() => {
                document.getElementById('gml-catalog-loading').textContent = 'Errore caricamento catalogo.';
            });
    }

    const tpl  = document.getElementById('tpl-catalog-row');
    const tbody = document.getElementById('gml-catalog-body');

    function renderCatalog(rows) {
        tbody.innerHTML = '';
        if (rows.length === 0) {
            document.getElementById('gml-catalog-empty').style.display = '';
            return;
        }
        document.getElementById('gml-catalog-table').style.display = '';
        for (const r of rows) {
            const tr = tpl.content.cloneNode(true).querySelector('tr');
            tr.dataset.belfiore = r.belfiore;
            tr.dataset.nome     = r.nome || '';
            tr.querySelector('.td-belfiore').textContent = r.belfiore;
            tr.querySelector('.td-nome').textContent     = r.nome || '—';
            tr.querySelector('.td-ple').innerHTML        = r.has_ple
                ? '<span class="badge bg-success">ple</span>'
                : '<span class="badge bg-secondary">—</span>';
            tr.querySelector('.td-map').innerHTML        = r.has_map
                ? '<span class="badge bg-info text-dark">map</span>'
                : '<span class="badge bg-secondary">—</span>';
            tr.querySelector('.td-parcels').textContent  = r.n_parcels > 0 ? r.n_parcels.toLocaleString('it-IT') : '—';
            tr.querySelector('.td-fogli').textContent    = r.n_fogli > 0 ? r.n_fogli : '—';
            tr.querySelector('.td-mtime').textContent    = r.mtime > 0
                ? new Date(r.mtime * 1000).toLocaleDateString('it-IT')
                : '—';

            tr.querySelector('.btn-inspect').addEventListener('click', () => inspectComune(r.belfiore));
            tr.querySelector('.btn-index').addEventListener('click', () => buildIndex(r.belfiore));
            tr.querySelector('.btn-delete').addEventListener('click', () => deleteComune(r.belfiore, tr));

            tbody.appendChild(tr);
        }
    }

    document.getElementById('gml-catalog-search').addEventListener('input', function () {
        const q = this.value.toLowerCase();
        tbody.querySelectorAll('tr').forEach(tr => {
            const match = tr.dataset.belfiore.toLowerCase().includes(q) || tr.dataset.nome.toLowerCase().includes(q);
            tr.style.display = match ? '' : 'none';
        });
    });

    document.getElementById('gml-rebuild-catalog-btn').addEventListener('click', () => {
        fetch(catalogUrl, {
            method: 'POST',
            body: new URLSearchParams({ action: 'rebuild', csrf_token: csrf }),
        }).then(() => loadCatalog());
    });

    // -------------------------------------------------------------------------
    // Diagnostica
    // -------------------------------------------------------------------------

    document.getElementById('gml-inspect-btn').addEventListener('click', () => {
        const b = document.getElementById('gml-inspect-belfiore').value.toUpperCase().trim();
        inspectComune(b);
    });

    function inspectComune(belfiore) {
        if (!belfiore) return;
        document.getElementById('gml-inspect-belfiore').value = belfiore;
        const div = document.getElementById('gml-inspect-result');
        div.innerHTML = '<div class="text-muted small">Analisi in corso…</div>';

        fetch(inspectUrl + '?belfiore=' + encodeURIComponent(belfiore))
            .then(r => r.json())
            .then(data => {
                if (!data.ok) { div.innerHTML = '<div class="alert alert-danger small py-2">' + escHtml(data.error) + '</div>'; return; }
                const r = data.report;
                let html = '<div class="small mt-2"><strong>' + escHtml(r.belfiore) + '</strong>';
                if (r.nome) html += ' — ' + escHtml(r.nome);
                html += '</div>';

                for (const [key, label] of [['ple', 'Particelle (_ple)'], ['map', 'Fogli (_map)']]) {
                    if (!r[key] || !r[key].path) continue;
                    const info = r[key];
                    const okBadge = info.ok
                        ? '<span class="badge bg-success">OK</span>'
                        : '<span class="badge bg-danger">KO</span>';
                    html += '<div class="border rounded p-2 mt-2 small">';
                    html += '<strong>' + label + '</strong> ' + okBadge + '<br>';
                    html += 'File: <code>' + escHtml(info.path) + '</code><br>';
                    html += 'Letti: <strong>' + info.letti + '</strong>';
                    if (info.number_matched !== null) html += ' / numberMatched: <strong>' + info.number_matched + '</strong>';
                    html += '<br>Fogli distinti: ' + info.fogli_distinti;
                    if (info.primi_fogli && info.primi_fogli.length) html += '<br>Primi fogli: <code>' + info.primi_fogli.map(escHtml).join(', ') + '</code>';
                    if (info.esempi_ref && info.esempi_ref.length) html += '<br>Esempi ref: <code>' + info.esempi_ref.map(escHtml).join(', ') + '</code>';
                    html += '</div>';
                }
                div.innerHTML = html;
            })
            .catch(() => { div.innerHTML = '<div class="alert alert-danger small py-2">Errore di rete.</div>'; });
    }

    // -------------------------------------------------------------------------
    // Build index (background job con log live)
    // -------------------------------------------------------------------------

    document.getElementById('gml-build-index-btn').addEventListener('click', () => buildIndex('ALL'));

    const indexLogWrap   = document.getElementById('gml-index-log-wrap');
    const indexLogBody   = document.getElementById('gml-index-log-body');
    const indexLogStatus = document.getElementById('gml-index-log-status');
    const indexLogTitle  = document.getElementById('gml-index-log-title');
    let indexPollTimer   = null;

    function buildIndex(belfiore) {
        const div = document.getElementById('gml-build-index-result');
        div.innerHTML = '<div class="text-muted small">Accodamento job in corso…</div>';

        // Mostra il pannello log
        indexLogWrap.style.display = '';
        indexLogBody.textContent = '';
        indexLogStatus.className = 'badge bg-secondary';
        indexLogStatus.textContent = 'in coda';
        indexLogTitle.textContent = 'Log indicizzazione (' + belfiore + ')';

        // Ferma eventuale polling precedente
        if (indexPollTimer !== null) {
            clearTimeout(indexPollTimer);
            indexPollTimer = null;
        }

        const body = new URLSearchParams({ belfiore: belfiore, csrf_token: csrf });
        fetch(indexUrl, { method: 'POST', body })
            .then(r => r.json())
            .then(data => {
                if (!data.ok) {
                    div.innerHTML = '<div class="alert alert-danger small py-2">' + escHtml(data.error || 'Errore') + '</div>';
                    if (data.job_id) {
                        // Mostra il messaggio anche nel log
                        appendLog('error', data.error || 'Errore avvio worker');
                        indexLogStatus.className = 'badge bg-danger';
                        indexLogStatus.textContent = 'failed';
                    }
                    return;
                }
                div.innerHTML = '<div class="text-muted small">Job #' + data.job_id + ' avviato in background.</div>';
                pollIndexJob(data.job_id, 0);
            })
            .catch(() => {
                div.innerHTML = '<div class="alert alert-danger small py-2">Errore di rete.</div>';
            });
    }

    function appendLog(level, message) {
        const colors = { info: '#aef', warning: '#fe8', error: '#f88' };
        const prefix = { info: '[info]   ', warning: '[warn]   ', error: '[ERROR]  ' };
        const color  = colors[level] || '#fff';
        const line   = document.createElement('span');
        line.style.color = color;
        line.textContent = (prefix[level] || '') + message + '\n';
        indexLogBody.appendChild(line);
        indexLogBody.scrollTop = indexLogBody.scrollHeight;
    }

    function pollIndexJob(jobId, afterId) {
        fetch(indexUrl + '?job_id=' + jobId + '&after_id=' + afterId)
            .then(r => r.json())
            .then(data => {
                if (!data.ok) return;
                const job  = data.job  || {};
                const logs = data.logs || [];
                let lastId = afterId;
                for (const l of logs) {
                    appendLog(l.level, l.message);
                    lastId = Math.max(lastId, l.id);
                }
                const status = job.status || 'queued';
                const statusBadge = { queued: 'bg-secondary', running: 'bg-primary', completed: 'bg-success', failed: 'bg-danger' };
                indexLogStatus.className = 'badge ' + (statusBadge[status] || 'bg-secondary');
                indexLogStatus.textContent = status;

                if (status === 'completed' || status === 'failed') {
                    const div = document.getElementById('gml-build-index-result');
                    if (status === 'completed') {
                        div.innerHTML = '<div class="alert alert-success small py-2">Indicizzazione completata (job #' + jobId + ').</div>';
                        loadCatalog();
                    } else {
                        div.innerHTML = '<div class="alert alert-danger small py-2">Indicizzazione fallita (job #' + jobId + '): ' + escHtml(job.error_message || '') + '</div>';
                    }
                    return; // smetti di fare polling
                }

                // Continua a fare polling ogni 2 secondi
                indexPollTimer = setTimeout(() => pollIndexJob(jobId, lastId), 2000);
            })
            .catch(() => {
                // Riprova dopo 3 secondi in caso di errore di rete
                indexPollTimer = setTimeout(() => pollIndexJob(jobId, afterId), 3000);
            });
    }

    // -------------------------------------------------------------------------
    // Delete comune
    // -------------------------------------------------------------------------

    function deleteComune(belfiore, tr) {
        if (!confirm('Eliminare tutti i file GML di ' + belfiore + '?')) return;
        const body = new URLSearchParams({ action: 'delete', belfiore: belfiore, csrf_token: csrf });
        fetch(catalogUrl, { method: 'POST', body })
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    tr.remove();
                } else {
                    alert('Errore: ' + (data.error || 'sconosciuto'));
                }
            });
    }

    // -------------------------------------------------------------------------
    // Util
    // -------------------------------------------------------------------------

    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // Avvia il caricamento del catalogo
    loadCatalog();

})();
</script>

<?php analyticspro_render_footer(true); ?>
