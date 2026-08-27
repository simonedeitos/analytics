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
if (analyticspro_is_subuser() && empty($subuserPermissions['can_view_analytics'])) {
    analyticspro_set_flash('danger', 'Accesso non consentito.');
    analyticspro_redirect('dashboard.php');
}

$tenantId       = analyticspro_current_tenant_id();
$selectedTenant = analyticspro_is_admin() ? (string) analyticspro_get('tenant_id', 'all') : (string) $tenantId;

analyticspro_render_header('Analitiche', ['app_assets' => true]);
?>
<div id="analyticspro-app"
     data-role="<?= analyticspro_h((string) $user['role']) ?>"
     data-tenant-id="<?= analyticspro_h((string) ($tenantId ?? '')) ?>"
     data-selected-tenant="<?= analyticspro_h($selectedTenant) ?>"
     data-can-view-phone="<?= analyticspro_tenant_phone_visibility($tenantId) ? '1' : '0' ?>"
     data-can-import="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_import']) ? '1' : '0' ?>"
     data-can-view-reports="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_view_reports']) ? '1' : '0' ?>"
     data-can-view-analytics="1"
     data-can-export="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_export']) ? '1' : '0' ?>"
     data-can-edit-all-markers="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_edit_all_markers']) ? '1' : '0' ?>"
     data-properties-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/properties.php')) ?>">

    <h1 class="h3 mb-4">Analitiche</h1>

    <div class="row g-3">
        <div class="col-md-6"><div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h6">Contatti</h2><canvas id="chart-contacts"></canvas></div></div></div>
        <div class="col-md-6"><div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h6">Genere</h2><canvas id="chart-gender"></canvas></div></div></div>
        <div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h6">Età</h2><canvas id="chart-age"></canvas></div></div></div>
        <div class="col-md-6"><div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h6">Province</h2><canvas id="chart-province"></canvas></div></div></div>
        <div class="col-md-6"><div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h6">Comuni</h2><canvas id="chart-comune"></canvas></div></div></div>
        <div class="col-md-6"><div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h6">Categorie</h2><canvas id="chart-categoria"></canvas></div></div></div>
        <div class="col-md-6"><div class="card border-0 shadow-sm"><div class="card-body"><h2 class="h6">Titolarità</h2><canvas id="chart-titolarita"></canvas></div></div></div>
    </div>
</div>
<?php analyticspro_render_footer(true); ?>
