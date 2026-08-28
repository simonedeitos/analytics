<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

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
     data-import-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/import.php')) ?>"
     data-import-progress-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/import_progress.php')) ?>"
     data-enrich-chunk-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/enrich_chunk.php')) ?>">

    <h1 class="h3 mb-4">Importa dati</h1>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h5">Importa dati da CSV / Excel</h2>
            <p class="text-muted small">Formati supportati: .csv, .xlsx, .xls. Il parsing lato client riusa SheetJS e invia al backend solo i record elaborati per il tenant corrente.</p>
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
        <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
                <h2 class="h5 mb-1">Coordinate mancanti</h2>
                <p class="text-muted small mb-0">Rilancia la geolocalizzazione per tutti gli immobili con coordinate non ancora risolte (lat / lng = NULL).</p>
            </div>
            <button id="rigenera-coordinate-btn" class="btn btn-outline-secondary">
                <i class="bi bi-geo-alt me-1"></i>Rigenera coordinate mancanti
            </button>
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
        <pre id="import-log-console" class="bg-dark text-light small p-2 rounded mb-2" style="max-height:220px;overflow:auto;white-space:pre-wrap;"></pre>
        <div id="enrichment-report" class="small d-none"></div>
    </div>
</div>
<?php analyticspro_render_footer(true); ?>
