<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/ui/helpers.php';

analyticspro_require_auth();
$user = analyticspro_current_user();
if (($user['role'] ?? '') === 'subuser' && !empty($user['must_change_password'])) {
    analyticspro_redirect('change_password.php');
}

$subuserPermissions = analyticspro_is_subuser() ? analyticspro_get_subuser_permissions((int) $user['id']) : null;
if (analyticspro_is_subuser() && empty($subuserPermissions['can_view_reports'])) {
    analyticspro_set_flash('danger', 'Accesso non consentito.');
    analyticspro_redirect('dashboard.php');
}

$tenantId       = analyticspro_current_tenant_id();
$selectedTenant = analyticspro_is_admin() ? (string) analyticspro_get('tenant_id', 'all') : (string) $tenantId;
$reportQuery    = trim((string) analyticspro_get('q', ''));

analyticspro_render_header('Proprietari', [
    'app_assets' => true,
    'extra_head' => '<link rel="stylesheet" href="' . analyticspro_h(analyticspro_asset_url('assets/css/map.css')) . '">'
        . '<link rel="stylesheet" href="' . analyticspro_h(analyticspro_asset_url('assets/css/owners.css')) . '">',
]);
?>
<div id="analyticspro-app"
     data-role="<?= analyticspro_h((string) $user['role']) ?>"
     data-tenant-id="<?= analyticspro_h((string) ($tenantId ?? '')) ?>"
     data-selected-tenant="<?= analyticspro_h($selectedTenant) ?>"
     data-can-view-phone="<?= analyticspro_tenant_phone_visibility($tenantId) ? '1' : '0' ?>"
     data-can-import="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_import']) ? '1' : '0' ?>"
     data-can-view-reports="1"
     data-can-view-analytics="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_view_analytics']) ? '1' : '0' ?>"
     data-can-export="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_export']) ? '1' : '0' ?>"
     data-can-edit-all-markers="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_edit_all_markers']) ? '1' : '0' ?>"
     data-properties-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/properties.php')) ?>"
     data-property-update-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/update_property.php')) ?>"
     data-property-delete-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/delete_property.php')) ?>"
     data-report-query="<?= analyticspro_h($reportQuery) ?>"
     class="owners-page">

    <header class="owners-header">
        <div>
            <h1>Censimento e proprietari</h1>
            <p>Tutti gli immobili e i contatti delle tue mappature</p>
        </div>
        <div class="owners-header-actions">
            <?php if (!analyticspro_is_subuser()): ?>

            <?php endif; ?>
            <a class="btn btn-primary" href="<?= analyticspro_h(analyticspro_base_url('mappa.php')) ?>"><i class="bi bi-map me-2" aria-hidden="true"></i>Apri mappa</a>
        </div>
    </header>

    <section class="owners-summary" aria-label="Riepilogo immobili caricati">
        <article class="owners-summary-card">
            <span class="owners-summary-icon"><i class="bi bi-house-door" aria-hidden="true"></i></span>
            <div><strong id="report-summary-properties" aria-live="polite">—</strong><span>Immobili</span></div>
        </article>
        <article class="owners-summary-card">
            <span class="owners-summary-icon"><i class="bi bi-people" aria-hidden="true"></i></span>
            <div><strong id="report-summary-owners" aria-live="polite">—</strong><span>Intestatari</span></div>
        </article>
        <article class="owners-summary-card">
            <span class="owners-summary-icon"><i class="bi bi-telephone" aria-hidden="true"></i></span>
            <div><strong id="report-summary-phones" aria-live="polite">—</strong><span>Con telefono</span></div>
        </article>
    </section>

<div class="card owners-grid">
    <div class="card-body">
        <div class="owners-grid-heading">
            <div class="owners-search-row">
                <div>
                    <label for="report-address-search" class="visually-hidden">Cerca indirizzo</label>
                    <div class="owners-search">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input id="report-address-search" type="search" class="form-control" placeholder="Cerca indirizzo...">
                    </div>
                </div>
                <div>
                    <label for="report-search" class="visually-hidden">Cerca proprietario</label>
                    <div class="owners-search">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input id="report-search" type="search" class="form-control" placeholder="Cerca per nome e cognome...">
                    </div>
                </div>
                <button id="report-filters-toggle" type="button" class="btn btn-outline-secondary btn-sm owners-filter-toggle" aria-expanded="false" aria-controls="report-filters" aria-label="Mostra filtri">
                    <i class="bi bi-funnel" aria-hidden="true"></i><span>Filtri</span>
                </button>
            </div>
        </div>

        <div id="report-filters" class="report-filter-bar owners-advanced-filters mb-3" hidden>
            <div class="owners-filter-row">
                <div class="analyticspro-map-field owners-filter-field">
                    <i class="bi bi-geo-alt" aria-hidden="true"></i>
                    <label for="report-filter-comune" class="visually-hidden">Comune</label>
                    <input id="report-filter-comune" class="form-control form-control-sm" placeholder="Comune">
                </div>
                <fieldset class="owners-cadastral-filter">
                    <legend class="visually-hidden">Foglio e particella</legend>
                    <div class="owners-cadastral-fields">
                        <div class="analyticspro-map-field owners-filter-field">
                            <i class="bi bi-hash" aria-hidden="true"></i>
                            <label for="report-filter-foglio" class="visually-hidden">Foglio</label>
                            <input id="report-filter-foglio" class="form-control form-control-sm" placeholder="F.">
                        </div>
                        <div class="analyticspro-map-field owners-filter-field">
                            <i class="bi bi-hash" aria-hidden="true"></i>
                            <label for="report-filter-particella" class="visually-hidden">Particella</label>
                            <input id="report-filter-particella" class="form-control form-control-sm" placeholder="P.">
                        </div>
                    </div>
                </fieldset>
                <div class="analyticspro-map-field owners-filter-field">
                    <i class="bi bi-chat-left-text" aria-hidden="true"></i>
                    <label for="report-filter-stato" class="visually-hidden">Stato contatto</label>
                    <select id="report-filter-stato" class="form-select form-select-sm">
                        <option value="">Tutti</option>
                    </select>
                </div>
                <div class="analyticspro-map-field owners-filter-field">
                    <i class="bi bi-person" aria-hidden="true"></i>
                    <label for="report-filter-assigned" class="visually-hidden">Assegnato a</label>
                    <input id="report-filter-assigned" class="form-control form-control-sm" placeholder="Assegnato a">
                </div>
                <div class="analyticspro-map-field owners-filter-field owners-color-filter">
                    <span id="report-filter-color-preview" class="color-dot" aria-hidden="true"></span>
                    <label for="report-filter-color" class="visually-hidden">Colore</label>
                    <select id="report-filter-color" class="form-select form-select-sm">
                        <option value="">Tutti</option>
                    </select>
                </div>
                <div class="analyticspro-map-field owners-filter-field">
                    <i class="bi bi-tags" aria-hidden="true"></i>
                    <label for="report-filter-categoria" class="visually-hidden">Categoria</label>
                    <select id="report-filter-categoria" class="form-select form-select-sm">
                        <option value="">Tutte</option>
                    </select>
                </div>
                <button id="report-filters-reset" type="button" class="btn btn-link owners-filter-reset">
                    <i class="bi bi-x-circle me-1" aria-hidden="true"></i>Azzera filtri
                </button>
            </div>
        </div>
            <div class="owners-table-scroll">
            <table id="report-table" class="table table-hover w-100 align-middle">
                <caption class="visually-hidden">Immobili e proprietari: contatti, stato, assegnazioni e azioni</caption>
                <thead></thead>
                <tfoot></tfoot>
                <tbody></tbody>
            </table>
            </div>
        </div>
    </div>
</div>
<?php analyticspro_render_footer(true); ?>
