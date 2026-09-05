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
if (analyticspro_is_subuser() && empty($subuserPermissions['can_view_reports'])) {
    analyticspro_set_flash('danger', 'Accesso non consentito.');
    analyticspro_redirect('dashboard.php');
}

$tenantId       = analyticspro_current_tenant_id();
$selectedTenant = analyticspro_is_admin() ? (string) analyticspro_get('tenant_id', 'all') : (string) $tenantId;

analyticspro_render_header('Report in griglia', ['app_assets' => true]);
?>
<div id="analyticspro-app"
     data-role="<?= analyticspro_h((string) $user['role']) ?>"
     data-tenant-id="<?= analyticspro_h((string) ($tenantId ?? '')) ?>"
     data-selected-tenant="<?= analyticspro_h($selectedTenant) ?>"
     data-can-view-phone="<?= analyticspro_tenant_phone_visibility($tenantId) ? '1' : '0' ?>"
     data-can-import="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_import']) ? '1' : '0' ?>"
     data-can-view-reports="1"
     data-can-view-analytics="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_view_analytics']) ? '1' : '0' ?>"
     data-can-export="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_export']) ? '1' : '0' ?>"
     data-can-edit-all-markers="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_edit_all_markers']) ? '1' : '0' ?>"
     data-properties-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/properties.php')) ?>"
     data-property-update-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/update_property.php')) ?>"
     data-property-delete-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/delete_property.php')) ?>">

    <h1 class="h3 mb-4">Report in griglia</h1>

    <div class="card border-0 shadow-sm">
        <div class="card-body">
            <p class="text-muted small">Vista generale di tutti gli immobili del tenant con filtri su ogni colonna, incluso colore e stato.</p>
            <div id="report-filters" class="report-filter-bar mb-3">
                <div class="row g-2 align-items-end">
                    <div class="col-12 col-md-2">
                        <label for="report-filter-color" class="form-label form-label-sm small mb-1">Colore</label>
                        <div class="d-flex align-items-center gap-2">
                            <span id="report-filter-color-preview" class="color-dot" aria-hidden="true"></span>
                            <select id="report-filter-color" class="form-select form-select-sm">
                                <option value="">Tutti</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-12 col-md-2">
                        <label for="report-filter-comune" class="form-label form-label-sm small mb-1">Comune</label>
                        <input id="report-filter-comune" class="form-control form-control-sm" placeholder="Comune">
                    </div>
                    <div class="col-12 col-md-2">
                        <label for="report-filter-foglio" class="form-label form-label-sm small mb-1">Foglio/Particella</label>
                        <input id="report-filter-foglio" class="form-control form-control-sm" placeholder="F. / P.">
                    </div>
                    <div class="col-12 col-md-2">
                        <label for="report-filter-stato" class="form-label form-label-sm small mb-1">Stato</label>
                        <select id="report-filter-stato" class="form-select form-select-sm">
                            <option value="">Tutti</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-4">
                        <label for="report-filter-assigned" class="form-label form-label-sm small mb-1">Assegnato a</label>
                        <input id="report-filter-assigned" class="form-control form-control-sm" placeholder="Nome subutente">
                    </div>
                </div>
            </div>
            <table id="report-table" class="table table-sm table-striped table-hover w-100 ap-compact-table">
                <thead></thead>
                <tfoot></tfoot>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>
<?php analyticspro_render_footer(true); ?>
