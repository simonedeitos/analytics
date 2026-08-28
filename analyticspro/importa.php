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
     data-import-progress-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/import_progress.php')) ?>">

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
</div>

<div class="modal fade" id="import-overlay" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-body p-4 text-center">
                <div class="spinner-border text-primary mb-3"></div>
                <h2 class="h5">Non chiudere la pagina finché l'importazione non è completata</h2>
                <div class="progress my-3" style="height: 10px;"><div id="import-progress-bar" class="progress-bar" style="width:0%"></div></div>
                <p class="small text-muted mb-0" id="import-progress-text">Preparazione import...</p>
            </div>
        </div>
    </div>
</div>
<?php analyticspro_render_footer(true); ?>
<!-- Enrichment status bar: shown by pollEnrichment() after import completes -->
<div id="enrichment-status-container" class="container mt-3" style="display:none">
    <div class="alert alert-info py-2 mb-0">
        <div class="progress mb-2" style="height:8px">
            <div id="enrichment-progress-bar" class="progress-bar bg-primary progress-bar-striped progress-bar-animated" style="width:0%"></div>
        </div>
        <p id="enrichment-progress-text" class="small mb-0">Geolocalizzazione marker in corso...</p>
    </div>
</div>
