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
$canViewPhone   = analyticspro_tenant_phone_visibility($tenantId);

analyticspro_render_header('Analitiche', ['app_assets' => true]);
?>
<div id="analyticspro-app"
     data-role="<?= analyticspro_h((string) $user['role']) ?>"
     data-tenant-id="<?= analyticspro_h((string) ($tenantId ?? '')) ?>"
     data-selected-tenant="<?= analyticspro_h($selectedTenant) ?>"
     data-can-view-phone="<?= $canViewPhone ? '1' : '0' ?>"
     data-can-import="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_import']) ? '1' : '0' ?>"
     data-can-view-reports="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_view_reports']) ? '1' : '0' ?>"
     data-can-view-analytics="1"
     data-can-export="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_export']) ? '1' : '0' ?>"
     data-can-edit-all-markers="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_edit_all_markers']) ? '1' : '0' ?>"
     data-properties-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/properties.php')) ?>">

    <h1 class="h3 mb-4">Analitiche</h1>

    <!-- KPI cards (mirror della sezione Statistiche dell'app root) -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center h-100">
                <div class="card-body py-3">
                    <div class="fs-1 text-primary mb-1"><i class="bi bi-people-fill"></i></div>
                    <div class="fs-3 fw-bold" data-kpi-analytics="total">—</div>
                    <div class="text-muted small">Intestatari totali</div>
                </div>
            </div>
        </div>
        <?php if ($canViewPhone): ?>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center h-100">
                <div class="card-body py-3">
                    <div class="fs-1 text-warning mb-1"><i class="bi bi-telephone-fill"></i></div>
                    <div class="fs-3 fw-bold" data-kpi-analytics="phone">—</div>
                    <div class="text-muted small">Con numero telefono</div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center h-100">
                <div class="card-body py-3">
                    <div class="fs-1 text-primary mb-1"><i class="bi bi-envelope-fill"></i></div>
                    <div class="fs-3 fw-bold" data-kpi-analytics="email">—</div>
                    <div class="text-muted small">Con email</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="card border-0 shadow-sm text-center h-100">
                <div class="card-body py-3">
                    <div class="fs-1 text-warning mb-1"><i class="bi bi-building"></i></div>
                    <div class="fs-3 fw-bold" data-kpi-analytics="piva">—</div>
                    <div class="text-muted small">Partite IVA</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts row 1: contacts + gender -->
    <div class="row g-4 mb-4">
        <div class="col-12 col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="fw-semibold mb-3"><i class="bi bi-telephone me-1"></i>Disponibilità Contatti</h6>
                    <canvas id="chart-contacts"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="fw-semibold mb-3"><i class="bi bi-gender-ambiguous me-1"></i>Distribuzione Sesso</h6>
                    <canvas id="chart-gender"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts row 2: age distribution -->
    <div class="row g-4 mb-4">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="fw-semibold mb-3"><i class="bi bi-bar-chart me-1"></i>Distribuzione per Fasce d'Età</h6>
                    <canvas id="chart-age"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts row 3: province + top comuni -->
    <div class="row g-4 mb-4">
        <div class="col-12 col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="fw-semibold mb-3"><i class="bi bi-geo-alt me-1"></i>Distribuzione per Provincia</h6>
                    <canvas id="chart-province"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="fw-semibold mb-3"><i class="bi bi-pin-map me-1"></i>Top 10 Comuni</h6>
                    <canvas id="chart-comune"></canvas>
                </div>
            </div>
        </div>
    </div>

    <!-- Charts row 4: category + ownership -->
    <div class="row g-4 mb-4">
        <div class="col-12 col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="fw-semibold mb-3"><i class="bi bi-house me-1"></i>Tipologie Immobili (Categoria)</h6>
                    <canvas id="chart-categoria"></canvas>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-6">
            <div class="card border-0 shadow-sm">
                <div class="card-body">
                    <h6 class="fw-semibold mb-3"><i class="bi bi-person-check me-1"></i>Titolarità</h6>
                    <canvas id="chart-titolarita"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>
<?php analyticspro_render_footer(true); ?>
