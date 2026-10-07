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
<div class="analyticspro-map-actions">
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
    <div class="dropdown analyticspro-map-toolbar-dropdown">
        <button class="btn btn-outline-primary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" data-bs-boundary="viewport" aria-expanded="false">
            <i class="bi bi-plus-lg me-1"></i>Ricerca Particella
        </button>
        <div class="dropdown-menu dropdown-menu-end p-3 shadow analyticspro-map-dropdown analyticspro-find-area-dropdown">
            <div class="small text-uppercase text-muted fw-semibold mb-2">Ricerca Particella</div>
            <div class="row g-2 align-items-end">
                <div class="col-12">
                    <label for="find-area-comune" class="form-label small mb-1">Comune</label>
                    <div class="position-relative">
                        <input id="find-area-comune" class="form-control form-control-sm" autocomplete="off" placeholder="Digita il Comune">
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
    <button class="btn btn-outline-secondary btn-sm" id="refresh-map">
        <i class="bi bi-arrow-clockwise me-1"></i>Aggiorna dati
    </button>
</div>
<?php
$mapHeaderActions = (string) ob_get_clean();
ob_start();
?>
<div class="analyticspro-map-toolbar">
    
    <div class="analyticspro-map-toolbar-primary">
        <div class="analyticspro-map-search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <label for="map-search" class="visually-hidden">Cerca indirizzo, comune o proprietario</label>
            <input id="map-search" type="search" class="form-control form-control-sm" placeholder="Cerca indirizzo, comune o proprietario...">
        </div>
        <div class="analyticspro-map-field">
            <i class="bi bi-geo-alt" aria-hidden="true"></i>
            <label for="map-comune-filter" class="visually-hidden">Comune</label>
            <select id="map-comune-filter" class="form-select form-select-sm"><option value="">Tutti i comuni</option></select>
        </div>
        <div class="dropdown analyticspro-map-toolbar-dropdown">
            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" data-bs-auto-close="outside" data-bs-boundary="viewport" aria-expanded="false">
                <i class="bi bi-chat-left-text me-2" aria-hidden="true"></i>Filtri
            </button>
            <div class="dropdown-menu p-3 shadow analyticspro-map-dropdown" id="map-filter-panel">
                <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap mb-2">
                    <strong class="small text-uppercase text-muted">Stati</strong>
                    <button id="btn-select-all-stati" type="button" class="btn btn-xs btn-outline-secondary">Deseleziona tutti</button>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-1 analyticspro-map-filter-list">
                    <?php foreach ([
                        '' => 'Non impostato',
                        'non_interessato' => 'Non Interessato',
                        'interessato' => 'Interessato',
                        'contattato' => 'Contattato',
                        'da_contattare' => 'Da Contattare',
                        'non_raggiungibile' => 'Non Raggiungibile',
                        'in_vendita_noi' => 'In Vendita NOI',
                        'in_vendita_altri' => 'In Vendita ALTRI',
                        'altro' => 'Altro',
                    ] as $value => $label): ?>
                        <div class="form-check form-check-inline me-0">
                            <input class="form-check-input map-stato-filter" type="checkbox" value="<?= analyticspro_h($value) ?>" id="filter-stato-<?= $value === '' ? 'null' : analyticspro_h(str_replace('_', '-', $value)) ?>" checked>
                            <label class="form-check-label" for="filter-stato-<?= $value === '' ? 'null' : analyticspro_h(str_replace('_', '-', $value)) ?>"><?= analyticspro_h($label) ?></label>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div id="map-category-filter-panel" class="d-flex flex-wrap align-items-center gap-1 mt-2 small"></div>
                <div class="d-flex justify-content-end mt-3 pt-2 border-top">
                    <button id="btn-apply-filter" type="button" class="btn btn-xs btn-primary">Applica</button>
                </div>
            </div>
        </div>
        <div class="analyticspro-map-field">
            <i class="bi bi-person" aria-hidden="true"></i>
            <label for="map-assigned-filter" class="visually-hidden">Assegnato a</label>
            <select id="map-assigned-filter" class="form-select form-select-sm"><option value="">Tutti</option></select>
        </div>
        <div class="form-check form-switch analyticspro-map-toolbar-switch mb-0">
            <input class="form-check-input" type="checkbox" role="switch" id="cadastral-layer-toggle">
            <label class="form-check-label small fw-semibold" for="cadastral-layer-toggle"><i class="bi bi-layers me-2" aria-hidden="true"></i>Layer catastale</label>
        </div>
    </div>
    
</div>
<?php
$mapTopbarControls = (string) ob_get_clean();

analyticspro_render_header('Mappa del territorio', [
    'app_assets' => true,
    'body_class' => 'map-page',
    'topbar_content' => $mapHeaderActions,
    'topbar_after' => $mapTopbarControls,
    'topbar_class' => 'analyticspro-map-header',
    'extra_head' => '<link rel="stylesheet" href="' . analyticspro_h(analyticspro_asset_url('assets/css/map.css')) . '">',
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
        <section id="map-visible-area" class="analyticspro-map-visible-area" aria-label="Area visibile">
            <div class="analyticspro-map-overlay-heading"><i class="bi bi-bounding-box" aria-hidden="true"></i> Area visibile</div>
            <div class="analyticspro-map-visible-counts" role="status" aria-live="polite">
                <div><strong id="map-visible-count">—</strong><span>Immobili</span></div>
                <div><strong id="map-visible-owners" aria-describedby="map-visible-owners-help">—</strong><span>Proprietari</span></div>
            </div>
            <small id="map-visible-owners-help" class="analyticspro-map-owners-help">Disponibile dopo aver aperto i dettagli</small>
            <?php if (!analyticspro_is_subuser() || !empty($subuserPermissions['can_view_reports'])): ?>
                <a class="analyticspro-map-list-link" href="<?= analyticspro_h(analyticspro_base_url('report.php') . (analyticspro_is_admin() ? '?tenant_id=' . rawurlencode($selectedTenant) : '')) ?>" title="Proprietari">Vedi elenco <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
            <?php endif; ?>
        </section>
        <aside class="analyticspro-map-legend" aria-label="Legenda stati contatto">
            <strong>Stato contatto</strong>
            <span><i class="analyticspro-map-legend-dot is-uncontacted" aria-hidden="true"></i>Non contattato</span>
            <span><i class="analyticspro-map-legend-dot is-contacted" aria-hidden="true"></i>Contattato</span>
            <span><i class="analyticspro-map-legend-dot is-recontact" aria-hidden="true"></i>Da ricontattare</span>
            <span><i class="analyticspro-map-legend-dot is-unreachable" aria-hidden="true"></i>Non raggiungibile</span>
            <small class="analyticspro-map-legend-note">Colori di riferimento; i marker mantengono i colori personalizzati.</small>
        </aside>
        <div id="cadastral-opacity-control" class="d-none analyticspro-cadastral-opacity-control">
            <i class="bi bi-layers-half text-muted" aria-hidden="true"></i>
            <input type="range"
                   id="cadastral-opacity-slider"
                   class="form-range"
                   min="15"
                   max="100"
                   step="1"
                   value="50"
                   aria-label="Opacità layer catastale">
            <span id="cadastral-opacity-value" class="small text-muted">50%</span>
        </div>
        <div id="map-cadastral-feedback" class="alert alert-light border shadow-sm d-none analyticspro-map-feedback" role="status"></div>
    </div>
</div>
<?php require __DIR__ . '/includes/manual_record_modal.php'; ?>
<?php analyticspro_render_footer(true); ?>
