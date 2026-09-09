<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/ui/helpers.php';
require_once __DIR__ . '/includes/dashboard_stats.php';

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
$period         = analyticspro_dashboard_period_key((string) analyticspro_get('period', '30d'));
$selectedProvince = trim((string) analyticspro_get('province', ''));
$selectedCategory = trim((string) analyticspro_get('category', ''));
$canExport = !analyticspro_is_subuser() || !empty($subuserPermissions['can_export']);

$scopeSql = 'SELECT DISTINCT provincia, categoria FROM properties';
$params = [];
if ($tenantId !== null) {
    $scopeSql .= ' WHERE user_id = :tenant_id';
    $params['tenant_id'] = $tenantId;
}
$scopeSql .= ' ORDER BY provincia, categoria';
$scopeStmt = analyticspro_db()->prepare($scopeSql);
$scopeStmt->execute($params);
$optionsRows = $scopeStmt->fetchAll() ?: [];
$provinceOptions = [];
$categoryOptions = [];
foreach ($optionsRows as $row) {
    $province = trim((string) ($row['provincia'] ?? ''));
    $category = trim((string) ($row['categoria'] ?? ''));
    if ($province !== '') {
        $provinceOptions[$province] = $province;
    }
    if ($category !== '') {
        $categoryOptions[$category] = $category;
    }
}
ksort($provinceOptions);
ksort($categoryOptions);

ob_start();
?>
<div class="d-flex align-items-center gap-2 flex-wrap">
    <label class="small text-muted fw-semibold" for="analytics-period-topbar">Periodo</label>
    <select id="analytics-period-topbar" class="form-select form-select-sm" data-dashboard-period-control>
        <option value="today" <?= $period === 'today' ? 'selected' : '' ?>>Oggi</option>
        <option value="7d" <?= $period === '7d' ? 'selected' : '' ?>>7 giorni</option>
        <option value="30d" <?= $period === '30d' ? 'selected' : '' ?>>30 giorni</option>
        <option value="year" <?= $period === 'year' ? 'selected' : '' ?>>Anno</option>
        <option value="all" <?= $period === 'all' ? 'selected' : '' ?>>Sempre</option>
    </select>
    <?php if ($canExport): ?>
        <button type="button" class="btn btn-outline-primary btn-sm" id="dashboard-export"><i class="bi bi-download me-1"></i>Export</button>
    <?php endif; ?>
</div>
<?php
$topbarContent = (string) ob_get_clean();

analyticspro_render_header('Analitiche avanzate', ['app_assets' => true, 'topbar_content' => $topbarContent]);

ob_start();
?>
<form class="d-flex align-items-center gap-2 flex-wrap" method="get">
    <input type="hidden" name="period" value="<?= analyticspro_h($period) ?>">
    <?php if (analyticspro_is_admin()): ?>
        <input type="hidden" name="tenant_id" value="<?= analyticspro_h($selectedTenant) ?>">
    <?php endif; ?>
    <div>
        <label class="small text-muted fw-semibold d-block mb-1" for="analytics-province-select">Provincia</label>
        <select class="form-select form-select-sm" id="analytics-province-select" name="province" data-dashboard-filter-control="province">
            <option value="">Tutte</option>
            <?php foreach ($provinceOptions as $province): ?>
                <option value="<?= analyticspro_h($province) ?>" <?= $selectedProvince === $province ? 'selected' : '' ?>><?= analyticspro_h($province) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div>
        <label class="small text-muted fw-semibold d-block mb-1" for="analytics-category-select">Categoria</label>
        <select class="form-select form-select-sm" id="analytics-category-select" name="category" data-dashboard-filter-control="category">
            <option value="">Tutte</option>
            <?php foreach ($categoryOptions as $category): ?>
                <option value="<?= analyticspro_h($category) ?>" <?= $selectedCategory === $category ? 'selected' : '' ?>><?= analyticspro_h($category) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</form>
<?php
$pageActions = (string) ob_get_clean();
?>
<div id="analyticspro-app"
     data-role="<?= analyticspro_h((string) $user['role']) ?>"
     data-tenant-id="<?= analyticspro_h((string) ($tenantId ?? '')) ?>"
     data-selected-tenant="<?= analyticspro_h($selectedTenant) ?>"
     data-can-view-phone="<?= analyticspro_tenant_phone_visibility($tenantId) ? '1' : '0' ?>"
     data-can-import="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_import']) ? '1' : '0' ?>"
     data-can-view-reports="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_view_reports']) ? '1' : '0' ?>"
     data-can-view-analytics="1"
     data-can-export="<?= $canExport ? '1' : '0' ?>"
     data-can-edit-all-markers="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_edit_all_markers']) ? '1' : '0' ?>"
     data-properties-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/properties.php')) ?>"
     data-dashboard-stats-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/dashboard_stats.php')) ?>"
     data-dashboard-period="<?= analyticspro_h($period) ?>"
     data-dashboard-page="analytics"
     data-dashboard-province="<?= analyticspro_h($selectedProvince) ?>"
     data-dashboard-category="<?= analyticspro_h($selectedCategory) ?>">

    <div class="card border-0 shadow-sm ap-hero-card mb-4">
        <div class="card-body">
            <?= analyticspro_ui_page_header(
                'Analitiche avanzate',
                'Vista deep dive con filtri per periodo, provincia e categoria, pensata per confrontare velocemente anagrafica, territorio e tipologie immobiliari.',
                $pageActions,
                ['eyebrow' => 'Deep dive']
            ) ?>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-12 col-md-6 col-xl-3"><article class="card border-0 shadow-sm ap-kpi-card"><div class="card-body"><div class="d-flex align-items-start justify-content-between gap-3"><div><div class="ap-kpi-label">Intestatari totali</div><div class="ap-kpi-value" data-kpi-analytics="total"><?= analyticspro_ui_skeleton(['width' => '7rem', 'height' => '2.5rem']) ?></div></div><span class="ap-kpi-icon ap-kpi-icon-success"><i class="bi bi-people"></i></span></div><div class="ap-kpi-meta mt-3">Anagrafica disponibile</div></div></article></div>
        <div class="col-12 col-md-6 col-xl-3"><article class="card border-0 shadow-sm ap-kpi-card"><div class="card-body"><div class="d-flex align-items-start justify-content-between gap-3"><div><div class="ap-kpi-label">Con telefono</div><div class="ap-kpi-value" data-kpi-analytics="phone"><?= analyticspro_ui_skeleton(['width' => '7rem', 'height' => '2.5rem']) ?></div></div><span class="ap-kpi-icon ap-kpi-icon-warning"><i class="bi bi-telephone"></i></span></div><div class="ap-kpi-meta mt-3">Disponibilità telefono</div></div></article></div>
        <div class="col-12 col-md-6 col-xl-3"><article class="card border-0 shadow-sm ap-kpi-card"><div class="card-body"><div class="d-flex align-items-start justify-content-between gap-3"><div><div class="ap-kpi-label">Con email</div><div class="ap-kpi-value" data-kpi-analytics="email"><?= analyticspro_ui_skeleton(['width' => '7rem', 'height' => '2.5rem']) ?></div></div><span class="ap-kpi-icon ap-kpi-icon-primary"><i class="bi bi-envelope"></i></span></div><div class="ap-kpi-meta mt-3">Copertura email</div></div></article></div>
        <div class="col-12 col-md-6 col-xl-3"><article class="card border-0 shadow-sm ap-kpi-card"><div class="card-body"><div class="d-flex align-items-start justify-content-between gap-3"><div><div class="ap-kpi-label">Partite IVA</div><div class="ap-kpi-value" data-kpi-analytics="piva"><?= analyticspro_ui_skeleton(['width' => '7rem', 'height' => '2.5rem']) ?></div></div><span class="ap-kpi-icon ap-kpi-icon-warning"><i class="bi bi-building"></i></span></div><div class="ap-kpi-meta mt-3">Soggetti aziendali</div></div></article></div>
    </div>

    <div class="row g-4 mb-4">
        <div class="col-12 col-xl-6"><?= analyticspro_ui_chart_card(['title' => 'Disponibilità contatti', 'icon' => 'bi-telephone', 'canvas_id' => 'chart-contacts', 'height' => '340']) ?></div>
        <div class="col-12 col-xl-6"><?= analyticspro_ui_chart_card(['title' => 'Distribuzione sesso', 'icon' => 'bi-gender-ambiguous', 'canvas_id' => 'chart-gender', 'height' => '340']) ?></div>
    </div>
    <div class="row g-4 mb-4">
        <div class="col-12"><?= analyticspro_ui_chart_card(['title' => 'Distribuzione per fasce d\'età', 'icon' => 'bi-bar-chart', 'canvas_id' => 'chart-age', 'height' => '320']) ?></div>
    </div>
    <div class="row g-4 mb-4">
        <div class="col-12 col-xl-6"><?= analyticspro_ui_chart_card(['title' => 'Distribuzione per provincia', 'icon' => 'bi-geo-alt', 'canvas_id' => 'chart-province', 'height' => '340']) ?></div>
        <div class="col-12 col-xl-6"><?= analyticspro_ui_chart_card(['title' => 'Top 10 comuni', 'icon' => 'bi-pin-map', 'canvas_id' => 'chart-comune', 'height' => '340']) ?></div>
    </div>
    <div class="row g-4">
        <div class="col-12 col-xl-6"><?= analyticspro_ui_chart_card(['title' => 'Tipologie immobili', 'icon' => 'bi-house', 'canvas_id' => 'chart-categoria', 'height' => '340']) ?></div>
        <div class="col-12 col-xl-6"><?= analyticspro_ui_chart_card(['title' => 'Titolarità', 'icon' => 'bi-person-check', 'canvas_id' => 'chart-titolarita', 'height' => '340']) ?></div>
    </div>
</div>
<?php analyticspro_render_footer(true); ?>
