<?php

declare(strict_types=1);
?>
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
                    <input type="hidden" name="Latitudine">
                    <input type="hidden" name="Longitudine">
                    <div id="manual-record-coordinates-summary" class="col-12 small text-muted d-none"></div>
                    <div class="col-12"><h3 class="h6 mb-0">Dati catastali</h3></div>
                    <div class="col-12">
                        <div id="manual-record-autofill-hint" class="manual-record-autofill-hint small text-muted d-none">
                            <i class="bi bi-lock-fill me-1" aria-hidden="true"></i>I campi evidenziati sono stati compilati automaticamente dalla mappa.
                        </div>
                    </div>
                    <div class="col-md-2"><label class="form-label small">Provincia</label><input class="form-control form-control-sm manual-record-lockable" name="Provincia"></div>
                    <div class="col-md-4"><label class="form-label small">Comune</label><input class="form-control form-control-sm manual-record-lockable" name="Comune"></div>
                    <div class="col-md-3"><label class="form-label small">Codice catastale</label><input class="form-control form-control-sm manual-record-lockable" name="Codice Catastale"></div>
                    <div class="col-md-3"><label class="form-label small">Sezione</label><input class="form-control form-control-sm manual-record-lockable" name="Sezione"></div>
                    <div class="col-md-3"><label class="form-label small">Foglio</label><input class="form-control form-control-sm manual-record-lockable" name="Foglio"></div>
                    <div class="col-md-3"><label class="form-label small">Particella</label><input class="form-control form-control-sm manual-record-lockable" name="Particella"></div>
                    <div class="col-md-3"><label class="form-label small">Subalterno</label><input class="form-control form-control-sm manual-record-lockable" name="Subalterno"></div>
                    <div class="col-md-3"><label class="form-label small">Civico</label><input class="form-control form-control-sm manual-record-lockable" name="Civico"></div>
                    <div class="col-md-6"><label class="form-label small">Indirizzo</label><input class="form-control form-control-sm manual-record-lockable" name="Indirizzo"></div>
                    <div class="col-md-2"><label class="form-label small">Categoria</label><input class="form-control form-control-sm manual-record-lockable" name="Categoria"></div>
                    <div class="col-md-2"><label class="form-label small">Classe</label><input class="form-control form-control-sm" name="Classe"></div>
                    <div class="col-md-2"><label class="form-label small">Piano</label><input class="form-control form-control-sm" name="Piano"></div>
                    <div class="col-md-2"><label class="form-label small">Consistenza</label><input class="form-control form-control-sm" name="Consistenza"></div>
                    <div class="col-md-2"><label class="form-label small">Superficie</label><input class="form-control form-control-sm" name="Superficie"></div>
                    <div class="col-md-2"><label class="form-label small">Rendita</label><input class="form-control form-control-sm" name="Rendita"></div>
                    <div class="col-md-3"><label class="form-label small">Titolarità</label><input class="form-control form-control-sm" name="Titolarita"></div>
                    <div class="col-md-3"><label class="form-label small">Quota</label><input class="form-control form-control-sm manual-record-lockable" name="Quota"></div>
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
                    <div class="col-md-4"><label class="form-label small">Nato a</label><input class="form-control form-control-sm" name="Nato A" placeholder="Es. MONTICHIARI (BS)"></div>
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
