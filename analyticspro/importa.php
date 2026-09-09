<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/ui/helpers.php';

analyticspro_require_auth();
$user = analyticspro_current_user();
if (($user['role'] ?? '') === 'subuser' && !empty($user['must_change_password'])) {
    analyticspro_redirect('change_password.php');
}

$subuserPermissions = analyticspro_is_subuser() ? analyticspro_get_subuser_permissions((int) $user['id']) : null;
if (analyticspro_is_subuser() && empty($subuserPermissions['can_import'])) {
    analyticspro_set_flash('danger', 'Accesso non consentito.');
    analyticspro_redirect('dashboard.php');
}

$tenantId       = analyticspro_current_tenant_id();
$selectedTenant = analyticspro_is_admin() ? (string) analyticspro_get('tenant_id', 'all') : (string) $tenantId;

analyticspro_render_header('Importa dati', ['app_assets' => true]);
?>
<div id="analyticspro-app"
     data-role="<?= analyticspro_h((string) $user['role']) ?>"
     data-tenant-id="<?= analyticspro_h((string) ($tenantId ?? '')) ?>"
     data-selected-tenant="<?= analyticspro_h($selectedTenant) ?>"
     data-can-view-phone="<?= analyticspro_tenant_phone_visibility($tenantId) ? '1' : '0' ?>"
     data-can-import="1"
     data-can-view-reports="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_view_reports']) ? '1' : '0' ?>"
     data-can-view-analytics="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_view_analytics']) ? '1' : '0' ?>"
     data-can-export="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_export']) ? '1' : '0' ?>"
     data-can-edit-all-markers="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_edit_all_markers']) ? '1' : '0' ?>"
     data-properties-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/properties.php')) ?>"
     data-property-update-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/update_property.php')) ?>"
     data-property-delete-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/delete_property.php')) ?>"
     data-import-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/import.php')) ?>"
     data-import-progress-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/import_progress.php')) ?>"
     data-enrich-chunk-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/enrich_chunk.php')) ?>"
     data-missing-coordinates-stats-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/missing_coordinates_stats.php')) ?>"
     data-admin-import-gml-url="<?= analyticspro_h(analyticspro_base_url('admin/import_gml.php')) ?>">

    <?= analyticspro_ui_page_header(
        'Importa dati',
        'Carica file catastali provenienti da EasyCatasto, inserisci record manuali e monitora l\'arricchimento delle coordinate con uno stile coerente con la nuova app.',
        '',
        ['eyebrow' => 'Import & enrichment']
    ) ?>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h5">Importa dati da CSV / Excel</h2>
            <p class="text-muted small">Formati supportati: .csv, .xlsx, .xls. Il parsing lato client riusa SheetJS e invia al backend solo i record elaborati per il tenant corrente.</p>
            <div class="d-flex justify-content-end mb-3">
                <button type="button" class="btn btn-outline-primary btn-sm" id="open-manual-record-modal">
                    <i class="bi bi-plus-circle me-1"></i>Inserisci record manualmente
                </button>
            </div>
            <div id="import-drop-zone" class="drop-zone mb-2" tabindex="0" role="button" aria-label="Area upload file">
                <div class="drop-zone-content">
                    <i class="bi bi-cloud-upload drop-zone-icon"></i>
                    <p class="fw-semibold mb-1">Trascina i file qui</p>
                    <p class="text-muted small mb-3">oppure</p>
                    <label for="import-files" class="btn btn-primary px-4">
                        <i class="bi bi-folder2-open me-2"></i>Seleziona file
                    </label>
                    <input type="file" id="import-files" accept=".csv,.xlsx,.xls" multiple class="d-none">
                    <p class="text-muted small mt-3 mb-0">Formati supportati: <strong>.csv</strong>, <strong>.xlsx</strong>, <strong>.xls</strong></p>
                </div>
            </div>
            <div class="form-text">In caso di duplicato catastale con intestatario diverso verrà chiesta conferma prima dell'aggiornamento.</div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
                <div>
    <h2 class="h5 mb-1">Coordinate mancanti</h2>

    <p class="text-muted small mb-0">
        Rilancia la geolocalizzazione per tutti gli immobili con coordinate non ancora risolte (lat / lng = NULL).
    </p>

    <p class="text-muted small mb-0 mt-1">
        <strong>NB:</strong> Il Catasto Nazionale viene aggiornato indicativamente ogni 6 mesi.
        Se alcuni immobili non vengono localizzati, si consiglia di riprovare successivamente,
        in seguito ai prossimi aggiornamenti dei dati.
    </p>
</div>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <div id="missing-coordinates-manual-action-slot" class="d-flex gap-2"></div>
                    <button id="rigenera-coordinate-btn" class="btn btn-outline-secondary">
                        <i class="bi bi-geo-alt me-1"></i>Rigenera coordinate mancanti
                    </button>
                </div>
            </div>
            <div class="row g-3 align-items-start">
                <div class="col-lg-4">
                    <div class="border rounded-4 p-3 h-100">
                        <div class="small text-uppercase text-muted fw-semibold mb-2">Situazione corrente</div>
                        <div class="d-flex flex-wrap gap-2 mb-2">
                            <span class="badge text-bg-secondary" id="missing-coordinates-total-badge">Totale immobili: —</span>
                            <span class="badge text-bg-warning" id="missing-coordinates-recoverable-badge">Recuperabili immobili: —</span>
                            <span class="badge text-bg-dark" id="missing-coordinates-exhausted-badge">Esauriti immobili: —</span>
                        </div>
                        <p id="missing-coordinates-summary" class="small text-muted mb-0">Caricamento conteggi coordinate mancanti…</p>
                        <p class="small text-muted mt-2 mb-0">
                            <span data-bs-toggle="tooltip" title="Più immobili possono condividere la stessa particella catastale: la geolocalizzazione lavora per particella.">
                                Più immobili possono condividere la stessa particella catastale: la geolocalizzazione lavora per particella.
                            </span>
                        </p>
                    </div>
                </div>
                <div class="col-lg-8">
                    <div id="missing-coordinates-admin-panel" class="<?= analyticspro_is_admin() ? '' : 'd-none' ?>">
                        <div class="table-responsive border rounded-4">
                            <table class="table table-sm align-middle mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Tenant</th>
                                        <th class="text-end">Totale immobili</th>
                                        <th class="text-end">Recuperabili immobili</th>
                                        <th class="text-end">Esauriti immobili</th>
                                    </tr>
                                </thead>
                                <tbody id="missing-coordinates-admin-body">
                                    <tr><td colspan="4" class="text-center text-muted py-3 small">Caricamento conteggi…</td></tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div id="enrichment-status-container" class="card border-0 shadow-sm mb-4" style="display:none">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
            <h2 class="h5 mb-0">Stato import</h2>
            <span id="import-phase" class="badge bg-primary">In attesa</span>
        </div>
        <div class="progress mb-2" style="height:8px">
            <div id="import-progress-bar" class="progress-bar bg-primary progress-bar-striped progress-bar-animated" style="width:0%"></div>
        </div>
        <p id="import-progress-text" class="small mb-2 text-muted">Preparazione import...</p>
        <pre id="import-log-console" class="bg-dark text-light small p-3 rounded-4 mb-2" style="max-height:220px;overflow:auto;white-space:pre-wrap;"></pre>
        <div id="enrichment-report" class="small d-none"></div>
    </div>
</div>
<?php require __DIR__ . '/includes/manual_record_modal.php'; ?>
<?php analyticspro_render_footer(true); ?>
