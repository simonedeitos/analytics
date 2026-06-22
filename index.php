<?php
// index.php - EasyCatasto Analytics
// All data processing happens client-side in JavaScript
// PHP is used only to serve this page
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EasyCatasto Analytics</title>

    <!-- Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- DataTables + Bootstrap 5 integration -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">
    <!-- Leaflet + MarkerCluster -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css">
    <!-- Custom styles -->
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-dark" style="background-color:#2A519F;">
    <div class="container-fluid px-4">
        <span class="navbar-brand fw-bold fs-5">
            <i class="bi bi-building me-2"></i>EasyCatasto Analytics
        </span>
    </div>
</nav>

<!-- Upload Section -->
<div id="upload-section" class="container-fluid d-flex flex-column align-items-center justify-content-center" style="min-height: calc(100vh - 56px);">
    <div class="text-center mb-4">
        <h2 class="fw-bold" style="color:#2A519F;">Analisi Dati Catastali</h2>
        <p class="text-muted">Carica uno o più file di ricerca EasyCatasto per visualizzare statistiche e dati</p>
    </div>

    <!-- Drop Zone -->
    <div id="drop-zone" class="drop-zone" tabindex="0" role="button" aria-label="Area upload file">
        <div class="drop-zone-content">
            <i class="bi bi-cloud-upload drop-zone-icon"></i>
            <p class="fw-semibold mb-1">Trascina i file qui</p>
            <p class="text-muted small mb-3">oppure</p>
            <label for="file-input" class="btn btn-brand-primary px-4">
                <i class="bi bi-folder2-open me-2"></i>Seleziona file
            </label>
            <input type="file" id="file-input" accept=".csv,.xlsx,.xls" multiple class="d-none">
            <p class="text-muted small mt-3 mb-0">Formati supportati: <strong>.csv</strong>, <strong>.xlsx</strong>, <strong>.xls</strong></p>
        </div>
    </div>

    <!-- Progress -->
    <div id="upload-progress" class="d-none mt-4 w-100" style="max-width:520px;">
        <div class="d-flex justify-content-between small text-muted mb-1">
            <span id="progress-label">Elaborazione in corso…</span>
            <span id="progress-percent">0%</span>
        </div>
        <div class="progress" style="height:8px;">
            <div id="progress-bar" class="progress-bar progress-bar-striped progress-bar-animated"
                 role="progressbar" style="width:0%; background-color:#f28e0e;"></div>
        </div>
        <p id="progress-rows" class="text-muted small mt-2 text-center"></p>
    </div>

    <!-- Disclaimer -->
    <p class="disclaimer mt-4">
        <i class="bi bi-shield-lock me-1"></i>
        I file vengono elaborati in locale, nessun dato viene salvato o mantenuto in memoria
    </p>
</div>

<!-- Analytics Section (hidden until data is loaded) -->
<div id="analytics-section" class="d-none">

    <!-- Top bar with file info and reset -->
    <div class="analytics-topbar py-2 px-4 d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-3 flex-wrap">
            <span class="fw-semibold" style="color:#2A519F;"><i class="bi bi-table me-1"></i>EasyCatasto Analytics</span>
            <span id="info-files" class="badge bg-secondary"></span>
            <span id="info-rows" class="badge" style="background-color:#f28e0e;"></span>
        </div>
        <button id="btn-reset" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-arrow-counterclockwise me-1"></i>Carica nuovi file
        </button>
    </div>

    <!-- Tabs -->
    <div class="container-fluid px-3 px-md-4 pt-3">
        <ul class="nav nav-tabs" id="mainTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active fw-semibold" id="tab-stats-btn" data-bs-toggle="tab"
                        data-bs-target="#tab-stats" type="button" role="tab">
                    <i class="bi bi-bar-chart-fill me-1"></i>Statistiche
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-semibold" id="tab-data-btn" data-bs-toggle="tab"
                        data-bs-target="#tab-data" type="button" role="tab">
                    <i class="bi bi-grid-3x3 me-1"></i>Dati
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link fw-semibold" id="tab-mappa-btn" data-bs-toggle="tab"
                        data-bs-target="#tab-mappa" type="button" role="tab">
                    <i class="bi bi-map me-1"></i>Mappa
                </button>
            </li>
        </ul>

        <div class="tab-content pt-4" id="mainTabContent">

            <!-- ===== STATISTICHE TAB ===== -->
            <div class="tab-pane fade show active" id="tab-stats" role="tabpanel">

                <!-- Summary KPI cards -->
                <div class="row g-3 mb-4" id="kpi-cards">
                    <div class="col-6 col-md-3">
                        <div class="kpi-card">
                            <div class="kpi-icon"><i class="bi bi-people-fill"></i></div>
                            <div class="kpi-value" id="kpi-total">-</div>
                            <div class="kpi-label">Intestatari totali</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="kpi-card kpi-card--orange">
                            <div class="kpi-icon"><i class="bi bi-telephone-fill"></i></div>
                            <div class="kpi-value" id="kpi-phone">-</div>
                            <div class="kpi-label">Con numero telefono</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="kpi-card">
                            <div class="kpi-icon"><i class="bi bi-envelope-fill"></i></div>
                            <div class="kpi-value" id="kpi-email">-</div>
                            <div class="kpi-label">Con email</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="kpi-card kpi-card--orange">
                            <div class="kpi-icon"><i class="bi bi-building"></i></div>
                            <div class="kpi-value" id="kpi-piva">-</div>
                            <div class="kpi-label">Partite IVA</div>
                        </div>
                    </div>
                </div>

                <!-- Charts row 1: contacts + gender -->
                <div class="row g-4 mb-4">
                    <div class="col-12 col-md-6">
                        <div class="chart-card">
                            <h6 class="chart-title"><i class="bi bi-telephone me-1"></i>Disponibilità Contatti</h6>
                            <div class="chart-wrapper"><canvas id="chart-contacts"></canvas></div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="chart-card">
                            <h6 class="chart-title"><i class="bi bi-gender-ambiguous me-1"></i>Distribuzione Sesso</h6>
                            <div class="chart-wrapper"><canvas id="chart-gender"></canvas></div>
                        </div>
                    </div>
                </div>

                <!-- Charts row 2: age distribution -->
                <div class="row g-4 mb-4">
                    <div class="col-12">
                        <div class="chart-card">
                            <h6 class="chart-title"><i class="bi bi-bar-chart me-1"></i>Distribuzione per Fasce d'Età</h6>
                            <div class="chart-wrapper chart-wrapper--tall"><canvas id="chart-age"></canvas></div>
                        </div>
                    </div>
                </div>

                <!-- Charts row 3: province + city -->
                <div class="row g-4 mb-4">
                    <div class="col-12 col-md-6">
                        <div class="chart-card">
                            <h6 class="chart-title"><i class="bi bi-geo-alt me-1"></i>Distribuzione per Provincia</h6>
                            <div class="chart-wrapper"><canvas id="chart-province"></canvas></div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="chart-card">
                            <h6 class="chart-title"><i class="bi bi-pin-map me-1"></i>Top 10 Comuni</h6>
                            <div class="chart-wrapper"><canvas id="chart-city"></canvas></div>
                        </div>
                    </div>
                </div>

                <!-- Charts row 4: category + ownership -->
                <div class="row g-4 mb-4">
                    <div class="col-12 col-md-6">
                        <div class="chart-card">
                            <h6 class="chart-title"><i class="bi bi-house me-1"></i>Tipologie Immobili (Categoria)</h6>
                            <div class="chart-wrapper"><canvas id="chart-category"></canvas></div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="chart-card">
                            <h6 class="chart-title"><i class="bi bi-person-check me-1"></i>Titolarità</h6>
                            <div class="chart-wrapper"><canvas id="chart-ownership"></canvas></div>
                        </div>
                    </div>
                </div>

                <!-- VAT table -->
                <div class="row g-4 mb-5">
                    <div class="col-12">
                        <div class="chart-card">
                            <h6 class="chart-title"><i class="bi bi-briefcase me-1"></i>Immobili intestati a Partite IVA</h6>
                            <div class="table-responsive">
                                <table id="table-piva" class="table table-sm table-hover table-striped w-100">
                                    <thead class="table-dark"></thead>
                                    <tbody></tbody>
                                </table>
                                <p id="no-piva-msg" class="text-muted text-center py-3 d-none">Nessun immobile intestato a Partita IVA trovato nei dati caricati.</p>
                            </div>
                        </div>
                    </div>
                </div>

            </div><!-- /tab-stats -->

            <!-- ===== DATI TAB ===== -->
            <div class="tab-pane fade" id="tab-data" role="tabpanel">

                <!-- Filters panel -->
                <div class="filters-panel mb-3">
                    <div class="row g-2 align-items-end">

                        <div class="col-6 col-sm-4 col-md-2">
                            <label class="form-label form-label-sm">Telefono</label>
                            <select id="filter-phone" class="form-select form-select-sm">
                                <option value="">Tutti</option>
                                <option value="1">Con telefono</option>
                                <option value="0">Senza telefono</option>
                            </select>
                        </div>

                        <div class="col-6 col-sm-4 col-md-2">
                            <label class="form-label form-label-sm">Email</label>
                            <select id="filter-email" class="form-select form-select-sm">
                                <option value="">Tutti</option>
                                <option value="1">Con email</option>
                                <option value="0">Senza email</option>
                            </select>
                        </div>

                        <div class="col-6 col-sm-4 col-md-2">
                            <label class="form-label form-label-sm">Provincia</label>
                            <select id="filter-province" class="form-select form-select-sm">
                                <option value="">Tutte</option>
                            </select>
                        </div>

                        <div class="col-6 col-sm-4 col-md-2">
                            <label class="form-label form-label-sm">Comune</label>
                            <select id="filter-city" class="form-select form-select-sm">
                                <option value="">Tutti</option>
                            </select>
                        </div>

                        <div class="col-6 col-sm-4 col-md-2">
                            <label class="form-label form-label-sm">Categoria</label>
                            <select id="filter-category" class="form-select form-select-sm">
                                <option value="">Tutte</option>
                            </select>
                        </div>

                        <div class="col-6 col-sm-4 col-md-2">
                            <label class="form-label form-label-sm">Titolarità</label>
                            <select id="filter-ownership" class="form-select form-select-sm">
                                <option value="">Tutte</option>
                            </select>
                        </div>

                        <div class="col-6 col-sm-4 col-md-2">
                            <label class="form-label form-label-sm">Fascia d'età</label>
                            <select id="filter-age" class="form-select form-select-sm">
                                <option value="">Tutte</option>
                                <option value="0-17">0–17 anni</option>
                                <option value="18-29">18–29 anni</option>
                                <option value="30-44">30–44 anni</option>
                                <option value="45-59">45–59 anni</option>
                                <option value="60-74">60–74 anni</option>
                                <option value="75+">75+ anni</option>
                                <option value="unknown">Età sconosciuta</option>
                            </select>
                        </div>

                        <div class="col-6 col-sm-4 col-md-2">
                            <label class="form-label form-label-sm">Sesso</label>
                            <select id="filter-gender" class="form-select form-select-sm">
                                <option value="">Tutti</option>
                                <option value="M">Maschio</option>
                                <option value="F">Femmina</option>
                                <option value="A">Azienda</option>
                                <option value="?">Non determinato</option>
                            </select>
                        </div>

                        <div class="col-6 col-sm-4 col-md-2">
                            <label class="form-label form-label-sm">Tipo Soggetto</label>
                            <select id="filter-piva" class="form-select form-select-sm">
                                <option value="">Tutti</option>
                                <option value="1">Solo Partite IVA</option>
                                <option value="0">Solo persone fisiche</option>
                            </select>
                        </div>

                        <div class="col-6 col-sm-4 col-md-2 d-flex align-items-end">
                            <button id="btn-clear-filters" class="btn btn-sm btn-outline-secondary w-100">
                                <i class="bi bi-x-circle me-1"></i>Azzera
                            </button>
                        </div>

                    </div>
                </div>

                <!-- Action bar -->
                <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
                    <span id="data-count-label" class="text-muted small"></span>
                </div>

                <!-- Data table -->
                <div class="table-responsive">
                    <table id="table-data" class="table table-sm table-hover table-striped w-100" style="font-size:0.8rem;">
                        <thead class="table-dark"></thead>
                        <tbody></tbody>
                    </table>
                </div>

            </div><!-- /tab-data -->

            <!-- ===== MAPPA TAB ===== -->
            <div class="tab-pane fade" id="tab-mappa" role="tabpanel">
                <div id="map-status" class="alert alert-info mb-3 d-none">
                    <i class="bi bi-hourglass-split"></i>
                    <span id="map-status-text">Preparazione mappa in corso…</span>
                    <div class="progress mt-2">
                        <div id="map-progress-bar" class="progress-bar" style="width:0%;"></div>
                    </div>
                </div>

                <!-- Scan Progress (mostrato durante query WFS) -->
                <div id="scan-progress" class="alert alert-primary mb-3 d-none" style="border-left: 4px solid #2A519F;">
                    <div class="d-flex align-items-center mb-2">
                        <div class="spinner-border spinner-border-sm me-2" role="status" style="color:#2A519F;">
                            <span class="visually-hidden">Query WFS...</span>
                        </div>
                        <strong>Query WFS INSPIRE Agenzia delle Entrate</strong>
                    </div>

                    <p class="small mb-3">
                        Comune: <strong id="scan-comune-name">-</strong>
                    </p>

                    <div class="row g-2 small mb-3">
                        <div class="col-4">
                            <span class="text-muted">Tile interrogati:</span><br>
                            <strong id="scan-points">0/10 tiles</strong>
                        </div>
                        <div class="col-4">
                            <span class="text-muted">Particelle trovate:</span><br>
                            <strong class="text-success" id="scan-found">0</strong>
                        </div>
                        <div class="col-4">
                            <span class="text-muted">Tempo rimanente:</span><br>
                            <strong id="scan-eta">~30 sec</strong>
                        </div>
                    </div>

                    <div class="progress" style="height:10px;">
                        <div id="scan-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated"
                             role="progressbar" style="width:0%; background-color:#2A519F;"></div>
                    </div>

                    <p class="small text-muted mt-3 mb-0">
                        <i class="bi bi-info-circle"></i>
                        Stiamo interrogando il servizio WFS INSPIRE per ottenere le coordinate precise delle particelle catastali dal centroid dei poligoni.
                        Questa operazione viene eseguita <strong>una sola volta per comune</strong> (~30-60 secondi) e poi salvata in cache.
                    </p>
                </div>

                <div id="map-container"></div>

                <div class="mt-3">
                    <span class="badge bg-success me-2">
                        <i class="bi bi-geo-alt-fill"></i> Coordinate precise (AdE)
                    </span>
                    <span class="badge bg-warning text-dark me-2">
                        <i class="bi bi-geo-alt"></i> Coordinate approssimative (Nominatim)
                    </span>
                    <span class="badge bg-secondary">
                        <i class="bi bi-geo"></i> Non geocodificato
                    </span>
                </div>
            </div><!-- /tab-mappa -->

        </div><!-- /tab-content -->
    </div><!-- /container -->
</div><!-- /analytics-section -->

<!-- Toast for copy feedback -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index:9999;">
    <div id="copy-toast" class="toast align-items-center text-bg-success border-0" role="alert">
        <div class="d-flex">
            <div class="toast-body" id="copy-toast-msg">Numeri copiati negli appunti!</div>
            <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
        </div>
    </div>
</div>

<!-- Scripts -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- SheetJS for Excel/CSV parsing -->
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<!-- jQuery (required by DataTables) -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- DataTables -->
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<!-- Leaflet + MarkerCluster -->
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
<!-- App logic -->
<script src="assets/js/app.js"></script>
</body>
</html>
