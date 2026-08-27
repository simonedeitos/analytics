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
$tenantId           = analyticspro_current_tenant_id();
$selectedTenant     = analyticspro_is_admin() ? (string) analyticspro_get('tenant_id', 'all') : (string) $tenantId;
$tenants            = analyticspro_is_admin() ? analyticspro_fetch_tenants() : [];

analyticspro_render_header('Mappa', ['app_assets' => true]);
?>
<div id="analyticspro-app"
     data-role="<?= analyticspro_h((string) $user['role']) ?>"
     data-tenant-id="<?= analyticspro_h((string) ($tenantId ?? '')) ?>"
     data-selected-tenant="<?= analyticspro_h($selectedTenant) ?>"
     data-can-view-phone="<?= analyticspro_tenant_phone_visibility($tenantId) ? '1' : '0' ?>"
     data-can-import="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_import']) ? '1' : '0' ?>"
     data-can-view-reports="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_view_reports']) ? '1' : '0' ?>"
     data-can-view-analytics="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_view_analytics']) ? '1' : '0' ?>"
     data-can-export="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_export']) ? '1' : '0' ?>"
     data-can-edit-all-markers="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_edit_all_markers']) ? '1' : '0' ?>"
     data-properties-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/properties.php')) ?>"
     data-property-update-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/update_property.php')) ?>">

    <div class="d-flex justify-content-between align-items-center mb-3 gap-3 flex-wrap">
        <div>
            <h1 class="h3 mb-1">Mappa marker</h1>
            <p class="text-muted small mb-0">Stato, colore, note e assegnazioni sono condivisi all'interno del tenant.</p>
        </div>
        <div class="d-flex align-items-center gap-2 ms-lg-auto">
            <?php if (analyticspro_is_admin()): ?>
                <form method="get" class="d-flex align-items-center gap-2 mb-0">
                    <label class="form-label mb-0 small text-muted">Vista admin</label>
                    <select class="form-select form-select-sm" name="tenant_id" onchange="this.form.submit()">
                        <option value="all" <?= $selectedTenant === 'all' ? 'selected' : '' ?>>Tutti gli utenti</option>
                        <?php foreach ($tenants as $tenant): ?>
                            <option value="<?= analyticspro_h((string) $tenant['id']) ?>" <?= $selectedTenant === (string) $tenant['id'] ? 'selected' : '' ?>><?= analyticspro_h(analyticspro_full_name($tenant)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            <?php endif; ?>
            <button class="btn btn-outline-primary btn-sm" id="refresh-map">
                <i class="bi bi-arrow-clockwise me-1"></i>Aggiorna dati
            </button>
        </div>
    </div>

    <!-- Full-page map container; height = viewport minus topbar minus header bar above -->
    <div id="map-fullpage"></div>
</div>
<?php analyticspro_render_footer(true); ?>
