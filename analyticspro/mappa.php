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

ob_start();
?>
<div class="analyticspro-map-toolbar">
    <div class="analyticspro-map-toolbar-primary">
        <div class="form-check form-switch analyticspro-map-toolbar-switch mb-0">
            <input class="form-check-input" type="checkbox" role="switch" id="cadastral-layer-toggle">
            <label class="form-check-label small fw-semibold" for="cadastral-layer-toggle">Mostra layer catastale</label>
        </div>
        <div id="cadastral-opacity-control" class="d-none d-flex align-items-center gap-2 analyticspro-cadastral-opacity-control">
            <i class="bi bi-layers-half text-muted" aria-hidden="true"></i>
            <span class="small text-muted fw-semibold">Trasparenza</span>
            <input type="range"
                   id="cadastral-opacity-slider"
                   class="form-range mb-0"
                   min="0"
                   max="100"
                   step="1"
                   value="50"
                   aria-label="Trasparenza layer catastale"
                   style="width: 140px;">
            <span id="cadastral-opacity-value" class="small text-muted">50%</span>
        </div>
        <div class="dropdown">
            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                <i class="bi bi-funnel me-1"></i>Filtri mappa
            </button>
            <div class="dropdown-menu p-3 shadow analyticspro-map-dropdown" id="map-filter-panel">
                <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-2">
                    <strong class="small text-uppercase text-muted">Filtri stato</strong>
                    <div class="d-flex gap-2">
                        <button id="btn-select-all-stati" class="btn btn-xs btn-outline-secondary">Seleziona tutti</button>
                        <button id="btn-apply-filter" class="btn btn-xs btn-primary">Applica</button>
                    </div>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-1 analyticspro-map-filter-list">
                    <div class="form-check form-check-inline me-0">
                        <input class="form-check-input map-stato-filter" type="checkbox" value="" id="filter-stato-null" checked>
                        <label class="form-check-label" for="filter-stato-null">Non impostato</label>
                    </div>
                    <div class="form-check form-check-inline me-0">
                        <input class="form-check-input map-stato-filter" type="checkbox" value="non_interessato" id="filter-stato-non-interessato" checked>
                        <label class="form-check-label" for="filter-stato-non-interessato">Non Interessato</label>
                    </div>
                    <div class="form-check form-check-inline me-0">
                        <input class="form-check-input map-stato-filter" type="checkbox" value="interessato" id="filter-stato-interessato" checked>
                        <label class="form-check-label" for="filter-stato-interessato">Interessato</label>
                    </div>
                    <div class="form-check form-check-inline me-0">
                        <input class="form-check-input map-stato-filter" type="checkbox" value="contattato" id="filter-stato-contattato" checked>
                        <label class="form-check-label" for="filter-stato-contattato">Contattato</label>
                    </div>
                    <div class="form-check form-check-inline me-0">
                        <input class="form-check-input map-stato-filter" type="checkbox" value="da_contattare" id="filter-stato-da-contattare" checked>
                        <label class="form-check-label" for="filter-stato-da-contattare">Da Contattare</label>
                    </div>
                    <div class="form-check form-check-inline me-0">
                        <input class="form-check-input map-stato-filter" type="checkbox" value="non_raggiungibile" id="filter-stato-non-raggiungibile" checked>
                        <label class="form-check-label" for="filter-stato-non-raggiungibile">Non Raggiungibile</label>
                    </div>
                    <div class="form-check form-check-inline me-0">
                        <input class="form-check-input map-stato-filter" type="checkbox" value="in_vendita_noi" id="filter-stato-in-vendita-noi" checked>
                        <label class="form-check-label" for="filter-stato-in-vendita-noi">In Vendita NOI</label>
                    </div>
                    <div class="form-check form-check-inline me-0">
                        <input class="form-check-input map-stato-filter" type="checkbox" value="in_vendita_altri" id="filter-stato-in-vendita-altri" checked>
                        <label class="form-check-label" for="filter-stato-in-vendita-altri">In Vendita ALTRI</label>
                    </div>
                    <div class="form-check form-check-inline me-0">
                        <input class="form-check-input map-stato-filter" type="checkbox" value="altro" id="filter-stato-altro" checked>
                        <label class="form-check-label" for="filter-stato-altro">Altro</label>
                    </div>
                </div>
                <div id="map-category-filter-panel" class="d-flex flex-wrap align-items-center gap-1 mt-2 small"></div>
            </div>
        </div>
        <div class="dropdown">
            <button class="btn btn-outline-primary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
                <i class="bi bi-search me-1"></i>Trova area
            </button>
            <div class="dropdown-menu p-3 shadow analyticspro-map-dropdown analyticspro-find-area-dropdown">
                <div class="small text-uppercase text-muted fw-semibold mb-2">Trova area</div>
                <div class="row g-2 align-items-end">
                    <div class="col-12">
                        <label for="find-area-comune" class="form-label small mb-1">Comune</label>
                        <div class="position-relative">
                            <input id="find-area-comune" class="form-control form-control-sm" autocomplete="off" placeholder="Digita almeno 3 lettere">
                            <div id="find-area-comune-results" class="list-group analyticspro-autocomplete d-none" role="listbox" aria-label="Suggerimenti comuni"></div>
                        </div>
                    </div>
                    <div class="col-6">
                        <label for="find-area-foglio" class="form-label small mb-1">Foglio</label>
                        <input id="find-area-foglio" class="form-control form-control-sm" placeholder="Es. 34">
                    </div>
                    <div class="col-6">
                        <label for="find-area-particella" class="form-label small mb-1">Particella</label>
                        <input id="find-area-particella" class="form-control form-control-sm" placeholder="Es. 351">
                    </div>
                    <div class="col-12">
                        <button id="find-area-submit" type="button" class="btn btn-primary btn-sm w-100">Cerca</button>
                    </div>
                    <div class="col-12">
                        <div id="find-area-feedback" class="small text-muted mt-1">Cerca comune + foglio (+ particella opzionale).</div>
                    </div>
                </div>
            </div>
        </div>
        <button class="btn btn-outline-primary btn-sm" id="refresh-map">
            <i class="bi bi-arrow-clockwise me-1"></i>Aggiorna dati
        </button>
    </div>
    <?php if (analyticspro_is_admin()): ?>
        <form method="get" class="analyticspro-map-toolbar-secondary">
            <label class="form-label small text-muted fw-semibold" for="analyticspro-admin-tenant-select">Vista admin</label>
            <select id="analyticspro-admin-tenant-select" class="form-select form-select-sm" name="tenant_id" onchange="this.form.submit()">
                <option value="all" <?= $selectedTenant === 'all' ? 'selected' : '' ?>>Tutti gli utenti</option>
                <?php foreach ($tenants as $tenant): ?>
                    <option value="<?= analyticspro_h((string) $tenant['id']) ?>" <?= $selectedTenant === (string) $tenant['id'] ? 'selected' : '' ?>><?= analyticspro_h(analyticspro_full_name($tenant)) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    <?php endif; ?>
</div>
<?php
$mapTopbarControls = (string) ob_get_clean();

analyticspro_render_header('Mappa', [
    'app_assets' => true,
    'body_class' => 'map-page',
    'topbar_content' => $mapTopbarControls,
]);
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
     data-find-area-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/find_area.php')) ?>"
     data-find-area-comuni-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/find_area_comuni.php')) ?>"
     data-feature-info-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/feature_info.php')) ?>"
     data-wms-proxy-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/wms_proxy.php')) ?>">

    <div id="analyticspro-map-shell" class="analyticspro-map-shell">
        <div id="map-fullpage"></div>

        <div id="map-cadastral-zoom-hint" class="alert alert-warning shadow-sm d-none analyticspro-map-hint" role="status">
            <i class="bi bi-zoom-in me-1"></i>Ingrandisci la mappa almeno al livello 10 per vedere il layer catastale.
        </div>
        <div id="map-cadastral-feedback" class="alert alert-light border shadow-sm d-none analyticspro-map-feedback" role="status"></div>
    </div>
</div>
<?php require __DIR__ . '/includes/manual_record_modal.php'; ?>
<?php analyticspro_render_footer(true); ?>
