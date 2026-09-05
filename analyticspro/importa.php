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
if (analyticspro_is_subuser() && empty($subuserPermissions['can_import'])) {
    analyticspro_set_flash('danger', 'Accesso non consentito.');
    analyticspro_redirect('dashboard.php');
}

$tenantId       = analyticspro_current_tenant_id();
$selectedTenant = analyticspro_is_admin() ? (string) analyticspro_get('tenant_id', 'all') : (string) $tenantId;

analyticspro_render_header('Importa dati', ['app_assets' => true]);
?>
<div id="analyticspro-app"
     data-role="<?= analyticspro_h((string) $user['role']) ?>"
     data-tenant-id="<?= analyticspro_h((string) ($tenantId ?? '')) ?>"
     data-selected-tenant="<?= analyticspro_h($selectedTenant) ?>"
     data-can-view-phone="<?= analyticspro_tenant_phone_visibility($tenantId) ? '1' : '0' ?>"
     data-can-import="1"
     data-can-view-reports="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_view_reports']) ? '1' : '0' ?>"
     data-can-view-analytics="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_view_analytics']) ? '1' : '0' ?>"
     data-can-export="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_export']) ? '1' : '0' ?>"
     data-can-edit-all-markers="<?= !analyticspro_is_subuser() || !empty($subuserPermissions['can_edit_all_markers']) ? '1' : '0' ?>"
     data-properties-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/properties.php')) ?>"
     data-property-update-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/update_property.php')) ?>"
     data-property-delete-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/delete_property.php')) ?>"
     data-import-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/import.php')) ?>"
     data-import-progress-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/import_progress.php')) ?>"
     data-enrich-chunk-endpoint="<?= analyticspro_h(analyticspro_base_url('api/data/enrich_chunk.php')) ?>">

    <h1 class="h3 mb-4">Importa dati</h1>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body">
            <h2 class="h5">Importa dati da CSV / Excel</h2>
            <p class="text-muted small">Formati supportati: .csv, .xlsx, .xls. Il parsing lato client riusa SheetJS e invia al backend solo i record elaborati per il tenant corrente.</p>
            <div class="d-flex justify-content-end mb-3">
                <button type="button" class="btn btn-outline-primary btn-sm" id="open-manual-record-modal">
                    <i class="bi bi-plus-circle me-1"></i>Inserisci record manualmente
                </button>
            </div>
            <div id="import-drop-zone" class="drop-zone mb-2" tabindex="0" role="button" aria-label="Area upload file">
                <div class="drop-zone-content">
                    <i class="bi bi-cloud-upload drop-zone-icon"></i>
                    <p class="fw-semibold mb-1">Trascina i file qui</p>
                    <p class="text-muted small mb-3">oppure</p>
                    <label for="import-files" class="btn btn-primary px-4">
                        <i class="bi bi-folder2-open me-2"></i>Seleziona file
                    </label>
                    <input type="file" id="import-files" accept=".csv,.xlsx,.xls" multiple class="d-none">
                    <p class="text-muted small mt-3 mb-0">Formati supportati: <strong>.csv</strong>, <strong>.xlsx</strong>, <strong>.xls</strong></p>
                </div>
            </div>
            <div class="form-text">In caso di duplicato catastale con intestatario diverso verrà chiesta conferma prima dell'aggiornamento.</div>
        </div>
    </div>

    <div class="card border-0 shadow-sm mb-4">
        <div class="card-body d-flex align-items-center justify-content-between flex-wrap gap-2">
            <div>
                <h2 class="h5 mb-1">Coordinate mancanti</h2>
                <p class="text-muted small mb-0">Rilancia la geolocalizzazione per tutti gli immobili con coordinate non ancora risolte (lat / lng = NULL).</p>
            </div>
            <button id="rigenera-coordinate-btn" class="btn btn-outline-secondary">
                <i class="bi bi-geo-alt me-1"></i>Rigenera coordinate mancanti
            </button>
        </div>
    </div>
</div>

<div id="enrichment-status-container" class="card border-0 shadow-sm mb-4" style="display:none">
    <div class="card-body">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
            <h2 class="h5 mb-0">Stato import</h2>
            <span id="import-phase" class="badge bg-primary">In attesa</span>
        </div>
        <div class="progress mb-2" style="height:8px">
            <div id="import-progress-bar" class="progress-bar bg-primary progress-bar-striped progress-bar-animated" style="width:0%"></div>
        </div>
        <p id="import-progress-text" class="small mb-2 text-muted">Preparazione import...</p>
        <pre id="import-log-console" class="bg-dark text-light small p-2 rounded mb-2" style="max-height:220px;overflow:auto;white-space:pre-wrap;"></pre>
        <div id="enrichment-report" class="small d-none"></div>
    </div>
</div>
<div class="modal fade" id="manual-record-modal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h2 class="modal-title h5 mb-0">Inserimento manuale record</h2>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
            </div>
            <div class="modal-body">
                <div id="manual-record-feedback" class="alert d-none py-2"></div>
                <form id="manual-record-form" class="row g-3">
                    <div class="col-12"><h3 class="h6 mb-0">Dati catastali</h3></div>
                    <div class="col-md-2"><label class="form-label small">Provincia</label><input class="form-control form-control-sm" name="Provincia"></div>
                    <div class="col-md-4"><label class="form-label small">Comune</label><input class="form-control form-control-sm" name="Comune"></div>
                    <div class="col-md-3"><label class="form-label small">Codice catastale</label><input class="form-control form-control-sm" name="Codice Catastale"></div>
                    <div class="col-md-3"><label class="form-label small">Sezione</label><input class="form-control form-control-sm" name="Sezione"></div>
                    <div class="col-md-3"><label class="form-label small">Foglio</label><input class="form-control form-control-sm" name="Foglio"></div>
                    <div class="col-md-3"><label class="form-label small">Particella</label><input class="form-control form-control-sm" name="Particella"></div>
                    <div class="col-md-3"><label class="form-label small">Subalterno</label><input class="form-control form-control-sm" name="Subalterno"></div>
                    <div class="col-md-3"><label class="form-label small">Civico</label><input class="form-control form-control-sm" name="Civico"></div>
                    <div class="col-md-6"><label class="form-label small">Indirizzo</label><input class="form-control form-control-sm" name="Indirizzo"></div>
                    <div class="col-md-2"><label class="form-label small">Categoria</label><input class="form-control form-control-sm" name="Categoria"></div>
                    <div class="col-md-2"><label class="form-label small">Classe</label><input class="form-control form-control-sm" name="Classe"></div>
                    <div class="col-md-2"><label class="form-label small">Piano</label><input class="form-control form-control-sm" name="Piano"></div>
                    <div class="col-md-2"><label class="form-label small">Consistenza</label><input class="form-control form-control-sm" name="Consistenza"></div>
                    <div class="col-md-2"><label class="form-label small">Superficie</label><input class="form-control form-control-sm" name="Superficie"></div>
                    <div class="col-md-2"><label class="form-label small">Rendita</label><input class="form-control form-control-sm" name="Rendita"></div>
                    <div class="col-md-3"><label class="form-label small">Titolarità</label><input class="form-control form-control-sm" name="Titolarita"></div>
                    <div class="col-md-3"><label class="form-label small">Quota</label><input class="form-control form-control-sm" name="Quota"></div>
                    <div class="col-12"><hr class="my-1"></div>
                    <div class="col-12"><h3 class="h6 mb-0">Intestatario e contatti</h3></div>
                    <div class="col-md-4"><label class="form-label small">Cognome</label><input class="form-control form-control-sm" name="Cognome"></div>
                    <div class="col-md-4"><label class="form-label small">Nome</label><input class="form-control form-control-sm" name="Nome"></div>
                    <div class="col-md-4"><label class="form-label small">Nome1</label><input class="form-control form-control-sm" name="Nome1"></div>
                    <div class="col-md-4"><label class="form-label small">Nome2</label><input class="form-control form-control-sm" name="Nome2"></div>
                    <div class="col-md-4"><label class="form-label small">Nome3</label><input class="form-control form-control-sm" name="Nome3"></div>
                    <div class="col-md-4"><label class="form-label small">Codice fiscale / P.IVA</label><input class="form-control form-control-sm" name="Codice Fiscale"></div>
                    <div class="col-md-6"><label class="form-label small">Contatti</label><textarea class="form-control form-control-sm" name="Contatti" rows="2" placeholder="Es. 3380000000,0300000000 - nome@email.it"></textarea></div>
                    <div class="col-md-6"><label class="form-label small">Indirizzo proprietario</label><input class="form-control form-control-sm" name="Indirizzo Proprietario"></div>
                    <div class="col-md-4"><label class="form-label small">Data nascita</label><input class="form-control form-control-sm" name="Data Nascita" placeholder="GG/MM/AAAA oppure AAAA-MM-GG"></div>
                    <div class="col-12"><label class="form-label small">Note</label><textarea class="form-control form-control-sm" name="Note" rows="3"></textarea></div>
                </form>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
                <button type="button" class="btn btn-primary" id="save-manual-record-btn">Salva record</button>
            </div>
        </div>
    </div>
</div>
<?php analyticspro_render_footer(true); ?>
