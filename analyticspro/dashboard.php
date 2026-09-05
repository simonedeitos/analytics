<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

analyticspro_require_auth();
$user = analyticspro_current_user();
if (($user['role'] ?? '') === 'subuser' && !empty($user['must_change_password'])) {
    analyticspro_redirect('change_password.php');
}

$tenantId           = analyticspro_current_tenant_id();
$selectedTenant     = analyticspro_is_admin() ? (string) analyticspro_get('tenant_id', 'all') : (string) $tenantId;
$subuserPermissions = analyticspro_is_subuser() ? analyticspro_get_subuser_permissions((int) $user['id']) : null;
$tenants            = analyticspro_is_admin() ? analyticspro_fetch_tenants() : [];

analyticspro_render_header('Dashboard', ['app_assets' => true]);
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
     data-property-update-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/update_property.php')) ?>"
     data-property-delete-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/delete_property.php')) ?>"
     data-import-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/import.php')) ?>"
     data-import-progress-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/import_progress.php')) ?>"
     data-ade-jobs-endpoint="<?= analyticspro_h(analyticspro_base_url('api/admin/ade_jobs.php')) ?>">

    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
        <div>
            <h1 class="h3 mb-1">Dashboard</h1>
            <p class="text-muted mb-0">Benvenuto/a, <?= analyticspro_h(analyticspro_full_name($user)) ?>. Qui trovi un riepilogo del tuo account.</p>
        </div>
        <?php if (analyticspro_is_admin()): ?>
            <form method="get" class="d-flex align-items-center gap-2">
                <label class="form-label mb-0 small text-muted">Vista admin</label>
                <select class="form-select form-select-sm" name="tenant_id" onchange="this.form.submit()">
                    <option value="all" <?= $selectedTenant === 'all' ? 'selected' : '' ?>>Tutti i tenant</option>
                    <?php foreach ($tenants as $tenant): ?>
                        <option value="<?= analyticspro_h((string) $tenant['id']) ?>" <?= $selectedTenant === (string) $tenant['id'] ? 'selected' : '' ?>><?= analyticspro_h(analyticspro_full_name($tenant)) ?></option>
                    <?php endforeach; ?>
                </select>
            </form>
        <?php endif; ?>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-xl-3"><div class="card metric-card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Immobili visibili</div><div class="display-6" data-kpi="properties">—</div></div></div></div>
        <div class="col-6 col-xl-3"><div class="card metric-card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Intestatari correnti</div><div class="display-6" data-kpi="owners">—</div></div></div></div>
        <div class="col-6 col-xl-3"><div class="card metric-card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Con telefono visibile</div><div class="display-6" data-kpi="phones">—</div></div></div></div>
        <div class="col-6 col-xl-3"><div class="card metric-card border-0 shadow-sm"><div class="card-body"><div class="text-muted small">Marker assegnati a me</div><div class="display-6" data-kpi="assigned">—</div></div></div></div>
    </div>

    <div class="row g-3">
        <div class="col-sm-6 col-lg-3">
            <a href="<?= analyticspro_h(analyticspro_base_url('mappa.php')) ?>" class="card border-0 shadow-sm text-decoration-none text-dark h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <i class="bi bi-map fs-2" style="color:var(--ap-blue)"></i>
                    <div><div class="fw-semibold">Mappa</div><div class="text-muted small">Esplora i marker</div></div>
                </div>
            </a>
        </div>
        <div class="col-sm-6 col-lg-3">
            <a href="<?= analyticspro_h(analyticspro_base_url('assegnati.php')) ?>" class="card border-0 shadow-sm text-decoration-none text-dark h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <i class="bi bi-pin-map fs-2" style="color:var(--ap-orange)"></i>
                    <div><div class="fw-semibold">Marker assegnati</div><div class="text-muted small">La tua lista</div></div>
                </div>
            </a>
        </div>
        <?php if (!analyticspro_is_subuser() || !empty($subuserPermissions['can_import'])): ?>
        <div class="col-sm-6 col-lg-3">
            <a href="<?= analyticspro_h(analyticspro_base_url('importa.php')) ?>" class="card border-0 shadow-sm text-decoration-none text-dark h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <i class="bi bi-upload fs-2" style="color:var(--ap-blue)"></i>
                    <div><div class="fw-semibold">Importa dati</div><div class="text-muted small">CSV / Excel</div></div>
                </div>
            </a>
        </div>
        <?php endif; ?>
        <?php if (!analyticspro_is_subuser() || !empty($subuserPermissions['can_view_reports'])): ?>
        <div class="col-sm-6 col-lg-3">
            <a href="<?= analyticspro_h(analyticspro_base_url('report.php')) ?>" class="card border-0 shadow-sm text-decoration-none text-dark h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <i class="bi bi-table fs-2" style="color:var(--ap-blue)"></i>
                    <div><div class="fw-semibold">Report</div><div class="text-muted small">Vista griglia</div></div>
                </div>
            </a>
        </div>
        <?php endif; ?>
        <?php if (!analyticspro_is_subuser() || !empty($subuserPermissions['can_view_analytics'])): ?>
        <div class="col-sm-6 col-lg-3">
            <a href="<?= analyticspro_h(analyticspro_base_url('analitiche.php')) ?>" class="card border-0 shadow-sm text-decoration-none text-dark h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <i class="bi bi-bar-chart-line fs-2" style="color:var(--ap-orange)"></i>
                    <div><div class="fw-semibold">Analitiche</div><div class="text-muted small">Grafici e statistiche</div></div>
                </div>
            </a>
        </div>
        <?php endif; ?>
        <?php if (analyticspro_is_main_user()): ?>
        <div class="col-sm-6 col-lg-3">
            <a href="<?= analyticspro_h(analyticspro_base_url('subutenti.php')) ?>" class="card border-0 shadow-sm text-decoration-none text-dark h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <i class="bi bi-people fs-2" style="color:var(--ap-blue)"></i>
                    <div><div class="fw-semibold">Subutenti</div><div class="text-muted small">Gestione permessi</div></div>
                </div>
            </a>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php analyticspro_render_footer(true); ?>
