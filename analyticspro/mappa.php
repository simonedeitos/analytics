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

analyticspro_render_header('Mappa', ['app_assets' => true, 'body_class' => 'map-page']);
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

        <div class="analyticspro-map-overlay analyticspro-map-overlay-top">
            <div class="card border-0 shadow-sm analyticspro-map-card">
                <div class="card-body py-2 px-3 d-flex justify-content-between align-items-center gap-3 flex-wrap">
                    <div>
                        <h1 class="h4 mb-1">Mappa marker</h1>
                        <div class="small text-muted">Fullscreen map con filtri flottanti e ricerca catastale rapida.</div>
                    </div>
                    <div class="d-flex align-items-center gap-2 ms-lg-auto flex-wrap">
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
                        <?php if (!analyticspro_is_subuser() || !empty($subuserPermissions['can_import'])): ?>
                            <button type="button" class="btn btn-outline-primary btn-sm" id="open-manual-record-modal">
                                <i class="bi bi-plus-circle me-1"></i>Aggiungi marker
                            </button>
                        <?php endif; ?>
                        <button class="btn btn-outline-primary btn-sm" id="refresh-map">
                            <i class="bi bi-arrow-clockwise me-1"></i>Aggiorna dati
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="analyticspro-map-overlay analyticspro-map-overlay-left">
            <div id="map-filter-panel" class="card border-0 shadow-sm analyticspro-map-card mb-3">
                <div class="card-body py-2 px-3">
                    <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-2">
                        <strong class="small text-uppercase text-muted">Filtri mappa</strong>
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
                    <hr class="my-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" role="switch" id="cadastral-layer-toggle">
                        <label class="form-check-label fw-semibold" for="cadastral-layer-toggle">Mostra layer catastale</label>
                    </div>
                    <div class="small text-muted mt-1">Attiva il layer AdE e clicca la mappa per leggere i dati catastali del punto selezionato.</div>
                </div>
            </div>

            <div class="card border-0 shadow-sm analyticspro-map-card">
                <div class="card-body py-2 px-3">
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
        </div>

        <div id="map-cadastral-zoom-hint" class="alert alert-warning shadow-sm d-none analyticspro-map-hint" role="status">
            <i class="bi bi-zoom-in me-1"></i>Ingrandisci la mappa per vedere il layer catastale.
        </div>
        <div id="map-cadastral-feedback" class="alert alert-light border shadow-sm d-none analyticspro-map-feedback" role="status"></div>
    </div>
</div>
<?php require __DIR__ . '/includes/manual_record_modal.php'; ?>
<?php analyticspro_render_footer(true); ?>
