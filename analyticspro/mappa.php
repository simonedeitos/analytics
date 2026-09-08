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
     data-property-update-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/update_property.php')) ?>"
     data-property-delete-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/delete_property.php')) ?>"
     data-find-area-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/find_area.php')) ?>"
     data-find-area-comuni-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/find_area_comuni.php')) ?>">

    <div class="d-flex justify-content-between align-items-center mb-3 gap-3 flex-wrap">
        <div>
            <h1 class="h4 mb-1">Mappa marker</h1>
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

        <!-- Filtro stato mappa -->
    <div id="map-filter-panel" class="card mb-2">
        <div class="card-body py-1 px-2">
            <div class="d-flex flex-wrap align-items-center gap-1" style="font-size: 0.75rem;">
                <strong class="me-1">Filtro stato:</strong>
                <div class="form-check form-check-inline me-0">
                    <input class="form-check-input map-stato-filter" type="checkbox" value="" id="filter-stato-null" checked style="width:0.75rem;height:0.75rem;">
                    <label class="form-check-label" for="filter-stato-null">Non impostato</label>
                </div>
                <div class="form-check form-check-inline me-0">
                    <input class="form-check-input map-stato-filter" type="checkbox" value="non_interessato" id="filter-stato-non-interessato" checked style="width:0.75rem;height:0.75rem;">
                    <label class="form-check-label" for="filter-stato-non-interessato">Non Interessato</label>
                </div>
                <div class="form-check form-check-inline me-0">
                    <input class="form-check-input map-stato-filter" type="checkbox" value="interessato" id="filter-stato-interessato" checked style="width:0.75rem;height:0.75rem;">
                    <label class="form-check-label" for="filter-stato-interessato">Interessato</label>
                </div>
                <div class="form-check form-check-inline me-0">
                    <input class="form-check-input map-stato-filter" type="checkbox" value="contattato" id="filter-stato-contattato" checked style="width:0.75rem;height:0.75rem;">
                    <label class="form-check-label" for="filter-stato-contattato">Contattato</label>
                </div>
                <div class="form-check form-check-inline me-0">
                    <input class="form-check-input map-stato-filter" type="checkbox" value="da_contattare" id="filter-stato-da-contattare" checked style="width:0.75rem;height:0.75rem;">
                    <label class="form-check-label" for="filter-stato-da-contattare">Da Contattare</label>
                </div>
                <div class="form-check form-check-inline me-0">
                    <input class="form-check-input map-stato-filter" type="checkbox" value="non_raggiungibile" id="filter-stato-non-raggiungibile" checked style="width:0.75rem;height:0.75rem;">
                    <label class="form-check-label" for="filter-stato-non-raggiungibile">Non Raggiungibile</label>
                </div>
                <div class="form-check form-check-inline me-0">
                    <input class="form-check-input map-stato-filter" type="checkbox" value="in_vendita_noi" id="filter-stato-in-vendita-noi" checked style="width:0.75rem;height:0.75rem;">
                    <label class="form-check-label" for="filter-stato-in-vendita-noi">In Vendita NOI</label>
                </div>
                <div class="form-check form-check-inline me-0">
                    <input class="form-check-input map-stato-filter" type="checkbox" value="in_vendita_altri" id="filter-stato-in-vendita-altri" checked style="width:0.75rem;height:0.75rem;">
                    <label class="form-check-label" for="filter-stato-in-vendita-altri">In Vendita ALTRI</label>
                </div>
                <div class="form-check form-check-inline me-0">
                    <input class="form-check-input map-stato-filter" type="checkbox" value="altro" id="filter-stato-altro" checked style="width:0.75rem;height:0.75rem;">
                    <label class="form-check-label" for="filter-stato-altro">Altro</label>
                </div>
<button id="btn-select-all-stati" class="btn btn-xs btn-outline-secondary" style="font-size:0.7rem;padding:0.1rem 0.4rem;margin-left:2rem;">Seleziona tutti</button>
<button id="btn-apply-filter" class="btn btn-xs btn-primary" style="font-size:0.7rem;padding:0.1rem 0.4rem;">Applica</button>
            </div>
            <div id="map-category-filter-panel" class="d-flex flex-wrap align-items-center gap-1 mt-2 small"></div>
        </div>
    </div>

    <div class="card mb-2">
        <div class="card-body py-2 px-3">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-4">
                    <label for="find-area-comune" class="form-label small mb-1">Trova area · Comune</label>
                    <input id="find-area-comune" class="form-control form-control-sm" list="find-area-comune-list" placeholder="Digita comune">
                    <datalist id="find-area-comune-list"></datalist>
                </div>
                <div class="col-6 col-md-2">
                    <label for="find-area-foglio" class="form-label small mb-1">Foglio</label>
                    <input id="find-area-foglio" class="form-control form-control-sm" placeholder="Es. 34">
                </div>
                <div class="col-6 col-md-2">
                    <label for="find-area-particella" class="form-label small mb-1">Particella (opz.)</label>
                    <input id="find-area-particella" class="form-control form-control-sm" placeholder="Es. 351">
                </div>
                <div class="col-12 col-md-2">
                    <button id="find-area-submit" type="button" class="btn btn-primary btn-sm w-100">Cerca</button>
                </div>
                <div class="col-12 col-md-2">
                    <div id="find-area-feedback" class="small text-muted mt-1">Cerca comune + foglio (+ particella opzionale).</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Full-page map container; height = viewport minus topbar minus header bar above -->
    <div id="map-fullpage"></div>
</div>
<?php analyticspro_render_footer(true); ?>
