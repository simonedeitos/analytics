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

$tenantId           = analyticspro_current_tenant_id();
$selectedTenant     = analyticspro_is_admin() ? (string) analyticspro_get('tenant_id', 'all') : (string) $tenantId;
$subuserPermissions = analyticspro_is_subuser() ? analyticspro_get_subuser_permissions((int) $user['id']) : null;
$period             = 'all';
$canImport          = !analyticspro_is_subuser() || !empty($subuserPermissions['can_import']);
$canViewAnalytics   = !analyticspro_is_subuser() || !empty($subuserPermissions['can_view_analytics']);
$canViewReports     = !analyticspro_is_subuser() || !empty($subuserPermissions['can_view_reports']);
$canExport          = !analyticspro_is_subuser() || !empty($subuserPermissions['can_export']);
$canViewPhone       = analyticspro_tenant_phone_visibility($tenantId);
$selectedComune     = trim((string) analyticspro_get('comune', ''));
$selectedCategory   = trim((string) analyticspro_get('category', ''));
$accountEmail       = trim((string) ($user['email'] ?? ''));
$comuneOptions      = [];
$categoryOptions    = [];

try {
    $importsSql = 'SELECT filename, status, processed_rows, total_rows, created_at FROM import_batches';
    $importsParams = [];
    if ($tenantId !== null) {
        $importsSql .= ' WHERE user_id = :tenant_id';
        $importsParams['tenant_id'] = $tenantId;
    }
    $importsSql .= ' ORDER BY created_at DESC LIMIT 5';
    $importsStmt = analyticspro_db()->prepare($importsSql);
    $importsStmt->execute($importsParams);
    $recentImports = $importsStmt->fetchAll() ?: [];
} catch (Throwable) {
    $recentImports = [];
}

try {
    $activitySql = 'SELECT comune, provincia, foglio, particella, updated_at FROM properties';
    $activityParams = [];
    if ($tenantId !== null) {
        $activitySql .= ' WHERE user_id = :tenant_id';
        $activityParams['tenant_id'] = $tenantId;
    }
    $activitySql .= ' ORDER BY updated_at DESC LIMIT 5';
    $activityStmt = analyticspro_db()->prepare($activitySql);
    $activityStmt->execute($activityParams);
    $recentActivity = $activityStmt->fetchAll() ?: [];
} catch (Throwable) {
    $recentActivity = [];
}

if ($canViewAnalytics) {
    try {
        $scopeSql = 'SELECT DISTINCT comune, categoria FROM properties';
        $scopeParams = [];
        if ($tenantId !== null) {
            $scopeSql .= ' WHERE user_id = :tenant_id';
            $scopeParams['tenant_id'] = $tenantId;
        }
        $scopeSql .= ' ORDER BY comune, categoria';
        $scopeStmt = analyticspro_db()->prepare($scopeSql);
        $scopeStmt->execute($scopeParams);
        foreach ($scopeStmt->fetchAll() ?: [] as $row) {
            $comune = trim((string) ($row['comune'] ?? ''));
            $category = trim((string) ($row['categoria'] ?? ''));
            if ($comune !== '') {
                $comuneOptions[$comune] = $comune;
            }
            if ($category !== '') {
                $categoryOptions[$category] = $category;
            }
        }
        ksort($comuneOptions);
        ksort($categoryOptions);
    } catch (Throwable) {
        $comuneOptions = [];
        $categoryOptions = [];
    }
}

$topbarContent = '';

analyticspro_render_header('Dashboard', ['app_assets' => true, 'topbar_content' => $topbarContent]);

ob_start();
?>
<div class="d-flex align-items-center gap-2 flex-wrap">
    <?php if ($canViewAnalytics): ?>
        <label class="small text-muted fw-semibold" for="dashboard-comune-select">Comune</label>
        <select id="dashboard-comune-select" class="form-select form-select-sm" data-dashboard-filter-control="comune">
            <option value="">Tutte</option>
            <?php foreach ($comuneOptions as $comune): ?>
                <option value="<?= analyticspro_h($comune) ?>" <?= $selectedComune === $comune ? 'selected' : '' ?>><?= analyticspro_h($comune) ?></option>
            <?php endforeach; ?>
        </select>
        <label class="small text-muted fw-semibold" for="dashboard-category-select">Categoria</label>
        <select id="dashboard-category-select" class="form-select form-select-sm" data-dashboard-filter-control="category">
            <option value="">Tutte</option>
            <?php foreach ($categoryOptions as $category): ?>
                <option value="<?= analyticspro_h($category) ?>" <?= $selectedCategory === $category ? 'selected' : '' ?>><?= analyticspro_h($category) ?></option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>
    <?php if ($canExport): ?>
        <button type="button" class="btn btn-outline-primary btn-sm" id="dashboard-export"><i class="bi bi-download me-1"></i>Export</button>
    <?php endif; ?>
    <?php if ($canImport): ?>
        <a href="<?= analyticspro_h(analyticspro_base_url('importa.php')) ?>" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle me-1"></i>Importa</a>
    <?php endif; ?>
</div>
<?php
$pageActions = (string) ob_get_clean();

$quickActions = [
    ['visible' => true, 'url' => 'mappa.php', 'icon' => 'bi-map', 'title' => 'Apri mappa', 'desc' => 'Esplora la distribuzione territoriale'],
    ['visible' => true, 'url' => 'assegnati.php', 'icon' => 'bi-pin-map', 'title' => 'Marker assegnati', 'desc' => 'Controlla la tua coda operativa'],
    ['visible' => $canImport, 'url' => 'importa.php', 'icon' => 'bi-upload', 'title' => 'Importa dati', 'desc' => 'Carica CSV o Excel'],
    ['visible' => $canViewReports, 'url' => 'report.php', 'icon' => 'bi-table', 'title' => 'Report', 'desc' => 'Apri la vista tabellare'],
    ['visible' => analyticspro_is_main_user(), 'url' => 'subutenti.php', 'icon' => 'bi-people', 'title' => 'Subutenti', 'desc' => 'Gestisci permessi e inviti'],
];
?>
<div id="analyticspro-app"
     data-role="<?= analyticspro_h((string) $user['role']) ?>"
     data-tenant-id="<?= analyticspro_h((string) ($tenantId ?? '')) ?>"
     data-selected-tenant="<?= analyticspro_h($selectedTenant) ?>"
     data-can-view-phone="<?= $canViewPhone ? '1' : '0' ?>"
     data-can-import="<?= $canImport ? '1' : '0' ?>"
     data-can-view-reports="<?= $canViewReports ? '1' : '0' ?>"
     data-can-view-analytics="<?= $canViewAnalytics ? '1' : '0' ?>"
     data-can-export="<?= $canExport ? '1' : '0' ?>"
     data-can-edit-all-markers="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_edit_all_markers']) ? '1' : '0' ?>"
     data-properties-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/properties.php')) ?>"
     data-property-update-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/update_property.php')) ?>"
     data-property-delete-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/delete_property.php')) ?>"
     data-import-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/import.php')) ?>"
     data-import-progress-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/import_progress.php')) ?>"
     data-ade-jobs-endpoint="<?= analyticspro_h(analyticspro_base_url('api/admin/ade_jobs.php')) ?>"
     data-dashboard-stats-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/dashboard_stats.php')) ?>"
     data-dashboard-period="<?= analyticspro_h($period) ?>"
     data-dashboard-comune="<?= analyticspro_h($selectedComune) ?>"
     data-dashboard-category="<?= analyticspro_h($selectedCategory) ?>"
     data-dashboard-page="home"
     data-dashboard-map-url="<?= analyticspro_h(analyticspro_base_url('mappa.php')) ?>">

    <div class="card border-0 shadow-sm ap-hero-card mb-4">
        <div class="card-body">
            <?= analyticspro_ui_page_header(
                'Ciao, ' . analyticspro_full_name($user),
                'Una home unificata per monitorare KPI, territorio e attività, tutto a portata di mano!',
                $pageActions,
                ['eyebrow' => 'Dashboard']
            ) ?>
            <ul class="nav nav-pills ap-dashboard-tabs mt-4" id="dashboardTabPills" role="tablist">
                <li class="nav-item"><button class="nav-link active" type="button" data-dashboard-tab="all">Panoramica</button></li>
                <li class="nav-item"><button class="nav-link" type="button" data-dashboard-tab="anagrafica">Anagrafica</button></li>
                <li class="nav-item"><button class="nav-link" type="button" data-dashboard-tab="territorio">Territorio</button></li>
                <li class="nav-item"><button class="nav-link" type="button" data-dashboard-tab="immobili">Immobili</button></li>
            </ul>
        </div>
    </div>

    <section data-dashboard-section="all overview immobili">
        <div class="row g-3 mb-4">
            <div class="col-12 col-md-6 col-xl-3"><?= analyticspro_ui_kpi_card(['icon' => 'bi-buildings', 'label' => 'Immobili', 'data_key' => 'properties', 'icon_tone' => 'primary']) ?></div>
            <div class="col-12 col-md-6 col-xl-3"><?= analyticspro_ui_kpi_card(['icon' => 'bi-people', 'label' => 'Intestatari', 'data_key' => 'owners', 'icon_tone' => 'success']) ?></div>
            <div class="col-12 col-md-6 col-xl-3"><?= analyticspro_ui_kpi_card(['icon' => 'bi-telephone', 'label' => 'Con telefono', 'data_key' => 'phones', 'icon_tone' => 'warning']) ?></div>
            <div class="col-12 col-md-6 col-xl-3"><?= analyticspro_ui_kpi_card(['icon' => 'bi-person-badge', 'label' => 'Assegnati a me', 'data_key' => 'assigned', 'icon_tone' => 'primary']) ?></div>
        </div>
    </section>

    <section class="row g-4 mb-4" data-dashboard-section="all overview territorio immobili">
        <div class="col-12 <?= $canViewAnalytics ? 'col-xl-8' : '' ?>">
            <?= analyticspro_ui_chart_card([
                'title' => 'Distribuzione territoriale',
                'icon' => 'bi-globe-europe-africa',
                'height' => '360',
                'actions' => '<a class="btn btn-outline-primary btn-sm" href="' . analyticspro_h(analyticspro_base_url('mappa.php')) . '">Apri mappa completa</a>',
                'body' => '<div id="dashboard-mini-map" class="ap-dashboard-map"></div>',
            ]) ?>
        </div>
        <?php if ($canViewAnalytics): ?>
            <div class="col-12 col-xl-4">
                <div class="row g-4">
                    <div class="col-12" data-dashboard-section="all overview anagrafica">
                        <?= analyticspro_ui_chart_card(['title' => 'Disponibilità contatti', 'icon' => 'bi-telephone', 'canvas_id' => 'chart-contacts', 'height' => '165']) ?>
                    </div>
                    <div class="col-12" data-dashboard-section="all overview immobili">
                        <?= analyticspro_ui_chart_card(['title' => 'Titolarità', 'icon' => 'bi-person-check', 'canvas_id' => 'chart-titolarita', 'height' => '165']) ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <?php if ($canViewAnalytics): ?>
        <section class="row g-4 mb-4" data-dashboard-section="all anagrafica territorio">
            <div class="col-12 col-xl-6"><?= analyticspro_ui_chart_card(['title' => 'Fasce d\'età', 'icon' => 'bi-bar-chart', 'canvas_id' => 'chart-age', 'height' => '300']) ?></div>
            <div class="col-12 col-xl-6"><?= analyticspro_ui_chart_card(['title' => 'Top 10 comuni', 'icon' => 'bi-geo-alt', 'canvas_id' => 'chart-comune', 'height' => '300']) ?></div>
        </section>
        <section class="row g-4 mb-4" data-dashboard-section="all territorio immobili">
            <div class="col-12 col-xl-4"><?= analyticspro_ui_chart_card(['title' => 'Distribuzione sesso', 'icon' => 'bi-gender-ambiguous', 'canvas_id' => 'chart-gender', 'height' => '300']) ?></div>
            <div class="col-12 col-xl-4"><?= analyticspro_ui_chart_card(['title' => 'Distribuzione per comuni', 'icon' => 'bi-pin-map', 'canvas_id' => 'chart-comune-distribution', 'height' => '300']) ?></div>
            <div class="col-12 col-xl-4"><?= analyticspro_ui_chart_card(['title' => 'Tipologie immobili', 'icon' => 'bi-house', 'canvas_id' => 'chart-categoria', 'height' => '300']) ?></div>
        </section>
    <?php else: ?>
        <section class="mb-4" data-dashboard-section="all anagrafica territorio immobili">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <?= analyticspro_ui_empty_state([
                        'icon' => 'bi-shield-lock',
                        'title' => 'Analitiche non disponibili',
                        'message' => 'Il tuo profilo non ha il permesso per visualizzare i grafici avanzati. Puoi comunque usare mappa, attività e azioni rapide.',
                    ]) ?>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <section class="row g-4" data-dashboard-section="all overview immobili">
        <div class="col-12 col-xl-8">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="row g-4">
                        <div class="col-12 col-lg-6">
                            <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
                                <h2 class="h5 mb-0">Attività recenti</h2>
                                <span class="badge text-bg-light">Ultimi aggiornamenti</span>
                            </div>
                            <?php if ($recentActivity): ?>
                                <div class="list-group list-group-flush ap-dense-list">
                                    <?php foreach ($recentActivity as $activity): ?>
                                        <div class="list-group-item px-0">
                                            <div class="fw-semibold"><?= analyticspro_h((string) $activity['comune']) ?> (<?= analyticspro_h((string) $activity['provincia']) ?>)</div>
                                            <div class="small text-muted">Foglio <?= analyticspro_h((string) $activity['foglio']) ?> · Particella <?= analyticspro_h((string) $activity['particella']) ?></div>
                                            <div class="small text-muted">Aggiornato il <?= analyticspro_h((string) $activity['updated_at']) ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <?= analyticspro_ui_empty_state(['icon' => 'bi-clock-history', 'title' => 'Nessuna attività recente', 'message' => 'Gli ultimi aggiornamenti agli immobili compariranno qui.']) ?>
                            <?php endif; ?>
                        </div>
                        <div class="col-12 col-lg-6">
                            <div class="d-flex align-items-center justify-content-between gap-2 mb-3">
                                <h2 class="h5 mb-0">Ultimi import</h2>
                                <span class="badge text-bg-light">Storico rapido</span>
                            </div>
                            <?php if ($recentImports): ?>
                                <div class="list-group list-group-flush ap-dense-list">
                                    <?php foreach ($recentImports as $import): ?>
                                        <div class="list-group-item px-0">
                                            <div class="fw-semibold"><?= analyticspro_h((string) $import['filename']) ?></div>
                                            <div class="small text-muted"><?= analyticspro_h((string) $import['processed_rows']) ?> / <?= analyticspro_h((string) $import['total_rows']) ?> righe · Stato <?= analyticspro_h((string) $import['status']) ?></div>
                                            <div class="small text-muted">Creato il <?= analyticspro_h((string) $import['created_at']) ?></div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <?= analyticspro_ui_empty_state(['icon' => 'bi-cloud-upload', 'title' => 'Nessun import disponibile', 'message' => 'Appena caricherai un CSV o un Excel, qui troverai gli ultimi batch.']) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-12 col-xl-4">
            <div class="row g-4">
                <div class="col-12">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <h2 class="h5 mb-3">Quick actions</h2>
                            <div class="d-grid gap-2">
                                <?php foreach ($quickActions as $action): ?>
                                    <?php if (!$action['visible']) continue; ?>
                                    <a href="<?= analyticspro_h(analyticspro_base_url($action['url'])) ?>" class="btn btn-outline-primary text-start">
                                        <i class="bi <?= analyticspro_h($action['icon']) ?> me-2"></i>
                                        <span class="fw-semibold"><?= analyticspro_h($action['title']) ?></span>
                                        <span class="d-block small text-muted"><?= analyticspro_h($action['desc']) ?></span>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-body">
                            <h2 class="h5 mb-3">Stato account</h2>
                            <div class="ap-account-status">
                                <div class="ap-account-status-item"><span>Ruolo</span><span class="badge text-bg-primary"><?= analyticspro_h((string) $user['role']) ?></span></div>
                                <div class="ap-account-status-item">
                                    <span>Email</span>
                                    <span class="badge text-bg-light ap-account-status-value text-truncate" title="<?= analyticspro_h($accountEmail !== '' ? $accountEmail : 'Email non disponibile') ?>"><?= analyticspro_h($accountEmail !== '' ? $accountEmail : '—') ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
<?php analyticspro_render_footer(true); ?>
