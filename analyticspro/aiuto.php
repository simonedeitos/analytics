<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';
require_once __DIR__ . '/includes/ui/helpers.php';

analyticspro_require_auth();
$user = analyticspro_current_user();
$role = (string) $user['role'];
$subPermissions = $role === 'subuser' ? analyticspro_get_subuser_permissions((int) $user['id']) : [];
analyticspro_render_header('Aiuto', ['body_class' => 'help-page']);
?>

<?= analyticspro_ui_page_header('Come possiamo aiutarti?', 'Guide semplici, passo dopo passo, per usare easyradar senza problemi.', '', ['eyebrow' => 'Aiuto']) ?>

<!-- ===== SEARCH BAR ===== -->
<div class="mb-4">
    <div class="input-group input-group-lg shadow-sm">
        <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
        <input type="text" id="helpSearch" class="form-control border-start-0 ps-0" aria-label="Cerca nella guida e nelle domande frequenti"
               placeholder="Cerca un argomento o una parola chiave..."
               autocomplete="off">
    </div>
</div>

<?php if ($role === 'subuser'): ?>
<!-- ===== SUBUSER PERMISSIONS BOX ===== -->
<div class="card border-primary shadow-sm mb-4">
    <div class="card-header bg-primary text-white fw-semibold"><i class="bi bi-shield-check me-2"></i>Cosa puoi fare con il tuo account</div>
    <div class="card-body">
        <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 g-2">
            <?php
            $perms = [
                ['icon' => 'bi-upload',          'label' => 'Importare dati',           'on' => !empty($subPermissions['can_import'])],
                ['icon' => 'bi-bar-chart-line',  'label' => 'Vedere le analitiche',     'on' => !empty($subPermissions['can_view_analytics'])],
                ['icon' => 'bi-table',           'label' => 'Vedere i proprietari',     'on' => !empty($subPermissions['can_view_reports'])],
                ['icon' => 'bi-download',        'label' => 'Esportare (CSV/Excel)',    'on' => !empty($subPermissions['can_export'])],
                ['icon' => 'bi-geo-alt',         'label' => 'Modificare tutti i marker', 'on' => !empty($subPermissions['can_edit_all_markers'])],
            ];
            foreach ($perms as $p):
            ?>
            <div class="col">
                <div class="d-flex align-items-center gap-2 p-2 rounded <?= $p['on'] ? 'bg-success-subtle text-success-emphasis' : 'bg-secondary-subtle text-secondary' ?>">
                    <i class="<?= analyticspro_h($p['icon']) ?> fs-5"></i>
                    <span class="small fw-medium"><?= analyticspro_h($p['label']) ?></span>
                    <span class="ms-auto badge <?= $p['on'] ? 'bg-success' : 'bg-secondary' ?>"><?= $p['on'] ? 'Sì' : 'No' ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php if (empty($subPermissions['can_edit_all_markers'])): ?>
        <p class="small text-muted mt-2 mb-0"><i class="bi bi-info-circle me-1"></i>Puoi modificare solo i marker (gli immobili sulla mappa) che ti sono stati assegnati.</p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- ===== NO RESULTS MESSAGES ===== -->
<div id="noResultsGuide" class="alert alert-warning d-none" role="alert"></div>
<div id="noResultsFaq"   class="alert alert-warning d-none" role="alert"></div>

<!-- ===== TWO-COLUMN LAYOUT ===== -->
<div class="row g-4 align-items-start">

    <!-- ============================================================
         LEFT COLUMN — GUIDE
    ============================================================ -->
    <div class="col-12 col-lg-6" id="guideColumn">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-primary text-white fw-semibold"><i class="bi bi-book me-2"></i>Guida all'uso</div>
            <div class="card-body p-0">
                <div class="accordion accordion-flush" id="guideAccordion">

                    <!-- ── PRIMI PASSI ── -->
                    <div class="accordion-item guide-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#g1">
                                <i class="bi bi-flag me-2 text-primary"></i>Primi passi
                            </button>
                        </h2>
                        <div id="g1" class="accordion-collapse collapse show" data-bs-parent="#guideAccordion">
                            <div class="accordion-body small">
                                <h6>Come creo un account</h6>
                                <p>Vai alla pagina di registrazione, inserisci nome, cognome, email e una password, poi invia la richiesta. Il tuo account resterà <em>in attesa di approvazione</em> finché un amministratore non lo attiva: riceverai una email appena sarà pronto.</p>

                                <h6 class="mt-3">Come accedo</h6>
                                <p>Inserisci email e password nella pagina di accesso. Se spunti <strong>Ricordami</strong>, non dovrai rifare il login per 10 ore. Se non la spunti, dovrai accedere di nuovo ogni volta che chiudi il browser.</p>

                                <h6 class="mt-3">Sono un subutente: come accedo la prima volta?</h6>
                                <p>Riceverai una email con un link e una password provvisoria. Clicca il link, accedi con quella password e il sistema ti chiederà subito di impostarne una nuova, tutta tua.</p>

                                <h6 class="mt-3">Chi può fare cosa</h6>
                                <ul>
                                    <li><strong>Utente principale</strong>: può fare tutto, vede tutti i dati della propria azienda e può invitare altre persone (subutenti).</li>
                                    <li><strong>Subutente</strong>: può lavorare solo con i permessi che gli vengono dati. Non può mai cancellare dati o immobili.</li>
                                </ul>
                                <p>Non preoccuparti della privacy: nessuno al di fuori della tua azienda può vedere i tuoi dati.</p>
                            </div>
                        </div>
                    </div>

                    <!-- ── IMPORT DATI ── -->
                    <div class="accordion-item guide-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#g2">
                                <i class="bi bi-upload me-2 text-primary"></i>Importa dati
                            </button>
                        </h2>
                        <div id="g2" class="accordion-collapse collapse" data-bs-parent="#guideAccordion">
                            <div class="accordion-body small">
                                <h6>Che file posso caricare</h6>
                                <p>Puoi caricare file <strong>Excel (.xlsx o .xls)</strong> o <strong>CSV</strong>, con le colonne degli immobili (comune, indirizzo, intestatari, contatti, ecc.).</p>

                                <h6 class="mt-3">Come si importa, passo dopo passo</h6>
                                <ol>
                                    <li>Apri <strong>Importa dati</strong> dal menu a sinistra.</li>
                                    <li>Trascina il file nella pagina, oppure clicca <em>Sfoglia</em> per selezionarlo dal computer.</li>
                                    <li>Premi il pulsante <strong>Importa</strong>.</li>
                                    <li>Aspetta che la barra di avanzamento arrivi al 100%. <strong>Non chiudere la pagina</strong> mentre l'importazione è in corso.</li>
                                </ol>

                                <h6 class="mt-3">Cosa succede se importo due volte lo stesso immobile</h6>
                                <p>Se il sistema trova un immobile già presente ma con un intestatario diverso, ti chiede cosa fare: puoi scegliere di <strong>aggiornare</strong> i dati o di <strong>lasciare tutto com'era</strong>. Nulla viene mai cancellato in automatico: i dati vecchi restano sempre consultabili nello storico.</p>

                                <h6 class="mt-3">Posso inserire un immobile a mano?</h6>
                                <p>Sì. Nella pagina <strong>Importa dati</strong> trovi anche un modulo per aggiungere manualmente un singolo immobile, senza bisogno di un file Excel.</p>
                            </div>
                        </div>
                    </div>

                    <!-- ── MAPPA E MARKER ── -->
                    <div class="accordion-item guide-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#g3">
                                <i class="bi bi-map me-2 text-primary"></i>Mappa e marker
                            </button>
                        </h2>
                        <div id="g3" class="accordion-collapse collapse" data-bs-parent="#guideAccordion">
                            <div class="accordion-body small">
                                <h6>Cosa sono i marker</h6>
                                <p>Ogni puntino sulla mappa è un immobile. All'inizio tutti i marker sono <strong>blu</strong>, senza nessuno stato assegnato.</p>

                                <h6 class="mt-3">Clicca su un marker per vedere i dettagli</h6>
                                <p>Cliccando su un marker si apre una scheda con l'indirizzo, gli intestatari, lo stato, le note e i contatti dell'immobile. Da qui puoi anche modificare i dati o cambiare lo stato.</p>

                                <h6 class="mt-3">Gruppi di marker (cluster)</h6>
                                <p>Quando ci sono tanti immobili vicini, vengono raggruppati in un cerchio con un numero. Clicca sul cerchio per avvicinarti e vedere i singoli immobili.</p>

                                <h6 class="mt-3">Gli stati che puoi assegnare a un immobile</h6>
                                <ul>
                                    <li>Non Interessato</li>
                                    <li>Interessato</li>
                                    <li>Contattato</li>
                                    <li>Da Contattare</li>
                                    <li>In Vendita NOI</li>
                                    <li>In Vendita ALTRI</li>
                                    <li>Altro (puoi scrivere tu cosa significa)</li>
                                </ul>
                                <p>Lo stato è visibile a tutte le persone della tua azienda (utente principale e subutenti).</p>

                                <h6 class="mt-3">Colore del marker</h6>
                                <p>Ogni stato ha già un colore automatico, ma puoi cambiarlo scegliendo tra i colori disponibili, se preferisci organizzarti diversamente.</p>

                                <h6 class="mt-3">Note sull'immobile</h6>
                                <p>Puoi scrivere una o più note su ogni immobile: utili per ricordare una telefonata fatta, un appuntamento o qualsiasi informazione. Ogni nota mostra chi l'ha scritta e quando.</p>

                                <h6 class="mt-3">Assegnare un immobile a qualcuno del team</h6>
                                <p>Dalla scheda del marker puoi assegnare l'immobile a uno o più subutenti. Loro lo troveranno subito nella loro sezione <strong>Assegnati a me</strong>.</p>

                                <h6 class="mt-3">Aggiungere un nuovo immobile dalla mappa</h6>
                                <p>Puoi anche cliccare direttamente su un punto della mappa per creare un nuovo marker in quella posizione.</p>
                            </div>
                        </div>
                    </div>

                    <!-- ── PROPRIETARI E PANORAMICA ── -->
                    <div class="accordion-item guide-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#g4">
                                <i class="bi bi-table me-2 text-primary"></i>Proprietari, Assegnati a me e Panoramica
                            </button>
                        </h2>
                        <div id="g4" class="accordion-collapse collapse" data-bs-parent="#guideAccordion">
                            <div class="accordion-body small">
                                <h6>"Assegnati a me"</h6>
                                <p>È l'elenco in tabella degli immobili che sono stati assegnati a te. Da qui puoi vedere e modificare velocemente tutti i dati principali.</p>

                                <h6 class="mt-3">"Proprietari"</h6>
                                <p>È l'elenco completo di tutti gli immobili della tua azienda, con filtri per trovare facilmente quello che cerchi (per città, stato, intestatario, ecc.).</p>

                                <h6 class="mt-3">"Panoramica"</h6>
                                <p>È la prima pagina che vedi dopo il login: mostra numeri riassuntivi (quanti immobili hai, in quali stati, in quali zone) e grafici che si aggiornano automaticamente man mano che importi o modifichi dati.</p>
                            </div>
                        </div>
                    </div>

                    <!-- ── TEAM E SUBUTENTI ── -->
                    <div class="accordion-item guide-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#g5">
                                <i class="bi bi-people me-2 text-primary"></i>Team e collaboratori (subutenti)
                            </button>
                        </h2>
                        <div id="g5" class="accordion-collapse collapse" data-bs-parent="#guideAccordion">
                            <div class="accordion-body small">
                                <h6>Come invito una persona a lavorare con me</h6>
                                <ol>
                                    <li>Vai nella sezione <strong>Team</strong> dal menu a sinistra.</li>
                                    <li>Scrivi nome, cognome ed email della persona.</li>
                                    <li>Premi <strong>INVITA</strong>: la persona riceverà automaticamente una email con le istruzioni per accedere.</li>
                                </ol>

                                <h6 class="mt-3">Cosa posso decidere per ogni collaboratore</h6>
                                <ul>
                                    <li>Se può modificare <strong>tutti</strong> gli immobili o solo quelli assegnati a lui.</li>
                                    <li>Se può <strong>importare</strong> dati.</li>
                                    <li>Se può vedere le <strong>analitiche</strong> (grafici e numeri).</li>
                                    <li>Se può vedere la sezione <strong>Proprietari</strong>.</li>
                                    <li>Se può <strong>esportare</strong> i dati in CSV/Excel.</li>
                                </ul>
                                <p>Puoi cambiare queste scelte in qualsiasi momento, tornando nella sezione Team.</p>

                                <h6 class="mt-3">Cosa non può fare un collaboratore</h6>
                                <p>Un subutente non può mai cancellare immobili o dati, e non può invitare altre persone. Può lavorare solo con i dati della tua azienda.</p>
                            </div>
                        </div>
                    </div>

                    <!-- ── PRIVACY E SICUREZZA ── -->
                    <div class="accordion-item guide-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#g6">
                                <i class="bi bi-shield-lock me-2 text-primary"></i>Privacy e sicurezza
                            </button>
                        </h2>
                        <div id="g6" class="accordion-collapse collapse" data-bs-parent="#guideAccordion">
                            <div class="accordion-body small">
                                <h6>Il numero di telefono è nascosto?</h6>
                                <p>Sì, per tutelare la privacy, i numeri di telefono sono nascosti di default. Solo se è stato espressamente abilitato, puoi vederli.</p>

                                <h6 class="mt-3">Come cambio la password</h6>
                                <p>Clicca sull'icona del tuo profilo in alto a destra, vai su <strong>Impostazioni account</strong> e segui le istruzioni. Ti verrà chiesta la password attuale per sicurezza.</p>

                                <h6 class="mt-3">I miei dati sono al sicuro?</h6>
                                <p>Sì. I dati sensibili (come nome, codice fiscale, indirizzo, telefono) vengono salvati in modo protetto e non sono leggibili da nessuno al di fuori della piattaforma.</p>

                                <h6 class="mt-3">Qualcuno di un'altra azienda può vedere i miei dati?</h6>
                                <p>No, mai. I dati di ogni azienda sono completamente separati da quelli delle altre.</p>
                            </div>
                        </div>
                    </div>

                </div><!-- /accordion guide -->
            </div>
        </div>
    </div><!-- /left column -->

    <!-- ============================================================
         RIGHT COLUMN — FAQ
    ============================================================ -->
    <div class="col-12 col-lg-6" id="faqColumn">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-primary text-white fw-semibold"><i class="bi bi-question-circle me-2"></i>Domande frequenti (FAQ)</div>
            <div class="card-body p-0">
                <div class="accordion accordion-flush" id="faqAccordion">
                    <?php
                    $faqs = [
                        [
                            'q' => 'Come mi registro su easyradar?',
                            'a' => 'Vai alla pagina di registrazione, compila il modulo con nome, cognome, email e password e invia la richiesta. Il tuo account resterà "in attesa di approvazione" finché un amministratore non lo attiva. Riceverai una email appena sarà pronto.',
                        ],
                        [
                            'q' => 'Quanto dura la sessione dopo il login?',
                            'a' => 'Se spunti "Ricordami" non devi rifare il login per 10 ore. Senza "Ricordami", basta chiudere il browser e al ritorno dovrai accedere di nuovo.',
                        ],
                        [
                            'q' => 'Ho ricevuto un\'email di invito: cosa devo fare?',
                            'a' => 'Clicca il link nell\'email, inserisci la password provvisoria indicata e accedi. Al primo accesso ti verrà chiesto di impostare subito una nuova password personale.',
                        ],
                        [
                            'q' => 'Posso vedere i dati di altre aziende?',
                            'a' => 'No. Ogni azienda vede solo i propri dati. Non esiste alcun modo per vedere i dati di altri.',
                        ],
                        [
                            'q' => 'Quali file posso importare?',
                            'a' => 'Puoi caricare file .csv, .xlsx oppure .xls con le informazioni degli immobili (indirizzo, intestatari, contatti, ecc.).',
                        ],
                        [
                            'q' => 'Posso chiudere la pagina mentre importo i dati?',
                            'a' => 'No, aspetta che la barra di avanzamento arrivi al 100% prima di chiudere la pagina o il browser, altrimenti l\'importazione potrebbe non completarsi.',
                        ],
                        [
                            'q' => 'Cosa succede se importo lo stesso immobile due volte con un intestatario diverso?',
                            'a' => 'Il sistema se ne accorge e ti chiede conferma su come procedere: puoi scegliere di aggiornare i dati oppure lasciare tutto com\'era. Niente viene mai cancellato in automatico.',
                        ],
                        [
                            'q' => 'Posso vedere chi era il proprietario di un immobile in passato?',
                            'a' => 'Sì. Lo storico di ogni immobile viene conservato: puoi sempre consultare chi erano gli intestatari precedenti e da quando.',
                        ],
                        [
                            'q' => 'Cosa succede se clicco su un gruppo di puntini sulla mappa?',
                            'a' => 'La mappa fa zoom su quella zona e il gruppo si divide in sotto-gruppi più piccoli, fino a mostrarti i singoli immobili.',
                        ],
                        [
                            'q' => 'Come posso cambiare il colore di un marker?',
                            'a' => 'Clicca sul marker per aprire la scheda, poi scegli un colore tra quelli disponibili. Il colore è separato dallo stato: puoi cambiarlo quando vuoi.',
                        ],
                        [
                            'q' => 'Un immobile ha più intestatari: come scelgo quale modificare?',
                            'a' => 'Apri la scheda del marker e clicca "Modifica": trovi un menu per scegliere quale intestatario aggiornare, oppure modificarli tutti insieme.',
                        ],
                        [
                            'q' => 'Perché un marker cambia colore da solo quando cambio stato?',
                            'a' => 'Ogni stato (es. Interessato, Contattato) ha un colore automatico assegnato di default. Puoi comunque scegliere un altro colore manualmente in qualsiasi momento.',
                        ],
                        [
                            'q' => 'Le note sugli immobili sono private?',
                            'a' => 'No, sono condivise con tutte le persone della tua azienda. Ogni nota indica chi l\'ha scritta e quando.',
                        ],
                        [
                            'q' => 'Posso assegnare un immobile a più collaboratori insieme?',
                            'a' => 'Sì. Dalla scheda del marker puoi assegnare l\'immobile a uno o più collaboratori contemporaneamente.',
                        ],
                        [
                            'q' => 'Qual è la differenza tra "Assegnati a me" e "Proprietari"?',
                            'a' => '"Assegnati a me" mostra solo gli immobili assegnati a te. "Proprietari" mostra l\'elenco completo di tutta l\'azienda, con filtri più avanzati.',
                        ],
                        [
                            'q' => 'Come posso esportare i dati?',
                            'a' => 'Dalla sezione "Assegnati a me" trovi i pulsanti per esportare in CSV o Excel (se hai il permesso attivo). L\'esportazione tiene conto dei filtri che hai applicato.',
                        ],
                        [
                            'q' => 'Perché non vedo il numero di telefono degli intestatari?',
                            'a' => 'Per proteggere la privacy, il numero di telefono è nascosto di default. Viene mostrato solo se è stato espressamente abilitato per la tua azienda.',
                        ],
                        [
                            'q' => 'Come cambio la password?',
                            'a' => 'Clicca sull\'icona del tuo profilo in alto a destra, vai su "Impostazioni account" e segui le istruzioni. Ti verrà chiesta la password attuale per sicurezza.',
                        ],
                        [
                            'q' => 'Posso cancellare un immobile se sono un collaboratore (subutente)?',
                            'a' => 'No. I collaboratori non possono mai cancellare immobili o dati, qualunque sia il permesso configurato per loro.',
                        ],
                        [
                            'q' => 'Come invito un collaboratore nel mio team?',
                            'a' => 'Vai nella sezione "Team" dal menu, inserisci nome, cognome ed email e premi "Invita". Il sistema invia automaticamente le credenziali di accesso via email.',
                        ],
                        [
                            'q' => 'Posso cambiare i permessi di un collaboratore dopo averlo invitato?',
                            'a' => 'Sì. Dalla sezione "Team" puoi modificare in ogni momento cosa può fare: modificare tutti gli immobili o solo quelli assegnati, importare dati, vedere analitiche e altro.',
                        ],
                        [
                            'q' => 'Un collaboratore può vedere le analitiche?',
                            'a' => 'Solo se gli viene dato esplicitamente il permesso dalla sezione "Team". Altrimenti i grafici e i numeri restano nascosti.',
                        ],
                        [
                            'q' => 'Come filtro per colore o stato nelle tabelle?',
                            'a' => 'Nelle sezioni "Assegnati a me" e "Proprietari" tutte le colonne, incluso colore e stato, hanno un filtro nella parte superiore della tabella.',
                        ],
                        [
                            'q' => 'I dati che importo sono al sicuro?',
                            'a' => 'Sì. I dati sensibili (nome, codice fiscale, indirizzo, telefono) vengono salvati in modo protetto e non sono leggibili da nessuno al di fuori della piattaforma.',
                        ],
                    ];
                    foreach ($faqs as $i => $faq):
                        $idx = $i + 1;
                    ?>
                    <div class="accordion-item faq-item">
                        <h2 class="accordion-header" id="faq-h-<?= $idx ?>">
                            <button class="accordion-button <?= $idx === 1 ? '' : 'collapsed' ?>" type="button"
                                    data-bs-toggle="collapse" data-bs-target="#faq-c-<?= $idx ?>">
                                <?= analyticspro_h($faq['q']) ?>
                            </button>
                        </h2>
                        <div id="faq-c-<?= $idx ?>" class="accordion-collapse collapse <?= $idx === 1 ? 'show' : '' ?>"
                             data-bs-parent="#faqAccordion">
                            <div class="accordion-body small"><?= analyticspro_h($faq['a']) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div><!-- /accordion faq -->
            </div>
        </div>
    </div><!-- /right column -->

</div><!-- /row -->

<script>
(function () {
    'use strict';

    var searchInput  = document.getElementById('helpSearch');
    var guideItems   = document.querySelectorAll('.guide-item');
    var faqItems     = document.querySelectorAll('.faq-item');
    var noResGuide   = document.getElementById('noResultsGuide');
    var noResFaq     = document.getElementById('noResultsFaq');

    function getTextOf(el) {
        return (el.textContent || el.innerText || '').toLowerCase();
    }

    function filterItems(items, needle, noResEl, label) {
        var visible = 0;
        items.forEach(function (item) {
            if (!needle || getTextOf(item).indexOf(needle) !== -1) {
                item.style.display = '';
                visible++;
            } else {
                item.style.display = 'none';
            }
        });
        if (needle && visible === 0) {
            noResEl.textContent = 'Nessun risultato trovato per \u00AB' + label + '\u00BB';
            noResEl.classList.remove('d-none');
        } else {
            noResEl.classList.add('d-none');
        }
    }

    searchInput.addEventListener('input', function () {
        var needle = this.value.trim().toLowerCase();
        filterItems(guideItems, needle, noResGuide, this.value.trim());
        filterItems(faqItems,   needle, noResFaq,   this.value.trim());
    });
}());
</script>

<?php analyticspro_render_footer(); ?>
