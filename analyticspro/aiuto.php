<?php

declare(strict_types=1);

require __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/layout.php';

analyticspro_require_auth();
$user = analyticspro_current_user();
$role = (string) $user['role'];
$subPermissions = $role === 'subuser' ? analyticspro_get_subuser_permissions((int) $user['id']) : [];
analyticspro_render_header('Aiuto');
?>

<!-- ===== SEARCH BAR ===== -->
<div class="mb-4">
    <div class="input-group input-group-lg shadow-sm">
        <span class="input-group-text bg-white border-end-0"><i class="bi bi-search text-muted"></i></span>
        <input type="text" id="helpSearch" class="form-control border-start-0 ps-0"
               placeholder="Cerca un argomento o una parola chiave..."
               autocomplete="off">
    </div>
</div>

<?php if ($role === 'subuser'): ?>
<!-- ===== SUBUSER PERMISSIONS BOX ===== -->
<div class="card border-primary shadow-sm mb-4">
    <div class="card-header bg-primary text-white fw-semibold"><i class="bi bi-shield-check me-2"></i>Permessi attivi per il tuo account</div>
    <div class="card-body">
        <div class="row row-cols-1 row-cols-sm-2 row-cols-md-3 g-2">
            <?php
            $perms = [
                ['icon' => 'bi-upload',          'label' => 'Import dati',              'on' => !empty($subPermissions['can_import'])],
                ['icon' => 'bi-bar-chart-line',  'label' => 'Analitiche',               'on' => !empty($subPermissions['can_view_analytics'])],
                ['icon' => 'bi-table',           'label' => 'Report in griglia',        'on' => !empty($subPermissions['can_view_reports'])],
                ['icon' => 'bi-download',        'label' => 'Export (CSV/Excel)',        'on' => !empty($subPermissions['can_export'])],
                ['icon' => 'bi-geo-alt',         'label' => 'Modifica tutti i marker',  'on' => !empty($subPermissions['can_edit_all_markers'])],
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
        <p class="small text-muted mt-2 mb-0"><i class="bi bi-info-circle me-1"></i>Puoi modificare solo i marker che ti sono stati assegnati dall'utente principale.</p>
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
                                <h6>Registrazione (utente principale)</h6>
                                <p>Vai alla pagina di registrazione pubblica e compila il modulo con nome, cognome, email e password. Al termine viene creato un account in stato <em>in attesa di approvazione</em>. L'amministratore riceve automaticamente una notifica email e deve approvare il tuo account prima che tu possa accedere. Una volta approvato riceverai una email di conferma.</p>

                                <h6 class="mt-3">Login e sessione</h6>
                                <p>Inserisci email e password nella pagina di accesso. Se spunti <strong>Ricordami</strong>, la sessione dura fino a <strong>10 ore</strong> dall'ultimo accesso, dopodiché dovrai autenticarti nuovamente. Senza "Ricordami" la sessione termina alla chiusura del browser. Non esiste persistenza della sessione oltre le 10 ore.</p>

                                <h6 class="mt-3">Primo accesso dei subutenti</h6>
                                <p>I subutenti non si registrano autonomamente: ricevono una <strong>email di invito</strong> dall'utente principale con un link di accesso e una <strong>password temporanea</strong>. Al primo login viene richiesto obbligatoriamente il cambio password prima di poter usare la piattaforma.</p>

                                <h6 class="mt-3">Ruoli nella piattaforma</h6>
                                <ul>
                                    <li><strong>Utente principale (tenant)</strong>: ha accesso completo ai propri dati, può creare e gestire subutenti, configurare permessi, importare dati e vedere tutte le sezioni.</li>
                                    <li><strong>Subutente</strong>: accede ai dati del tenant cui appartiene con i permessi che l'utente principale ha configurato. Non può eliminare dati o marker.</li>
                                </ul>
                                <p>I dati sono <strong>completamente isolati</strong> tra tenant diversi: utenti di tenant differenti non vedono mai i dati altrui.</p>
                            </div>
                        </div>
                    </div>

                    <!-- ── IMPORT DATI ── -->
                    <div class="accordion-item guide-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#g2">
                                <i class="bi bi-upload me-2 text-primary"></i>Import dati
                            </button>
                        </h2>
                        <div id="g2" class="accordion-collapse collapse" data-bs-parent="#guideAccordion">
                            <div class="accordion-body small">
                                <h6>Formati supportati</h6>
                                <p>Puoi importare file <strong>.csv</strong>, <strong>.xlsx</strong> e <strong>.xls</strong>. Il file deve contenere almeno le colonne catastali minime: <em>Provincia, Comune, Foglio, Particella, Subalterno, Indirizzo, Categoria, Codice Fiscale, Titolarità, Quota, Contatti, Data Nascita</em>. L'ordine delle colonne non è vincolante; l'intestazione viene riconosciuta in modo non case-sensitive e tutte le colonne previste dal template vengono lette anche se contengono testo o numeri in celle normalmente lasciate vuote.</p>

                                <h6 class="mt-3">Come avviare l'import</h6>
                                <ol>
                                    <li>Apri la sezione <strong>Import dati</strong> dal menu laterale (visibile solo se il permesso import è attivo).</li>
                                    <li>Trascina il file nell'area di caricamento oppure clicca <em>Sfoglia</em>.</li>
                                    <li>Premi <strong>Importa</strong>.</li>
                                    <li>Attendi il completamento: <strong>non chiudere la pagina</strong>. Compare un overlay bloccante con una barra di avanzamento che mostra lo stato reale di elaborazione riga per riga e il posizionamento dei marker sulla mappa.</li>
                                </ol>

                                <h6 class="mt-3">Duplicati con intestatario diverso</h6>
                                <p>Se durante l'import viene rilevato un immobile con gli stessi estremi catastali (Comune + Foglio + Particella + Subalterno) ma con un intestatario differente da quello già presente, la piattaforma mostra una <strong>finestra di conferma</strong>. Puoi scegliere di:</p>
                                <ul>
                                    <li><strong>Aggiornare</strong>: il vecchio intestatario viene storicizzato e il nuovo diventa quello corrente.</li>
                                    <li><strong>Mantenere</strong>: il dato esistente resta invariato e il nuovo viene ignorato.</li>
                                </ul>

                                <h6 class="mt-3">Contatti, note e nomi multipli</h6>
                                <p>La colonna <strong>Contatti</strong> può contenere più numeri separati da virgola: il sistema li importa tutti, rimuove separatori inutili come <code> -</code>, elimina i duplicati e li salva nello stesso campo separandoli con <code>;</code>. Se nel file è presente una colonna <strong>Note</strong> / <strong>note</strong>, il testo viene aggiunto alle note dell'immobile. Le colonne <strong>Nome</strong>, <strong>Nome1</strong>, <strong>Nome2</strong> e <strong>Nome3</strong> vengono unite automaticamente evitando ripetizioni.</p>

                                <h6 class="mt-3">Storico intestatari</h6>
                                <p>AnalyticsPRO conserva uno storico degli intestatari nel tempo. Ogni aggiornamento non sovrascrive il dato precedente ma lo archivia con la data di sostituzione, così puoi sempre sapere chi era il titolare in un determinato periodo.</p>

                                <h6 class="mt-3">Inserimento manuale</h6>
                                <p>Nella pagina <strong>Importa dati</strong> è disponibile anche un modulo per inserire manualmente un singolo record con dati catastali, intestatario, contatti e note. Se compili il modulo e provi a chiuderlo senza salvare, la piattaforma ti chiede conferma prima di scartare i dati.</p>
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
                                <h6>Colore di default</h6>
                                <p>Tutti i marker appaiono inizialmente in <strong>blu aziendale</strong> e senza alcuno stato assegnato.</p>

                                <h6 class="mt-3">Popup del marker</h6>
                                <p>Cliccando su un marker si apre un popup che mostra:</p>
                                <ul>
                                    <li>Dati catastali dell'immobile (Comune, Foglio, Particella, Subalterno, Indirizzo, Categoria) con intestazione rapida di <strong>Classe, Rendita, Piano, Consistenza</strong>.</li>
                                    <li>Elenco di tutti gli intestatari correnti con <strong><i class="bi bi-person"></i> icona persona</strong> per le persone fisiche o <strong><i class="bi bi-building"></i> icona azienda</strong> per le persone giuridiche.</li>
                                    <li>Stato attuale, colore, note, assegnazioni e pulsanti rapidi per copiare i numeri di telefono.</li>
                                    <li>Nel modal <strong>Modifica marker</strong>, per ogni intestatario puoi eliminare i singoli numeri non più validi con il pulsante <strong>✕</strong> (con conferma esplicita prima del salvataggio definitivo).</li>
                                </ul>

                                <h6 class="mt-3">Stato del marker</h6>
                                <p>Puoi assegnare uno dei seguenti stati:</p>
                                <ul>
                                    <li>Non Interessato</li>
                                    <li>Interessato</li>
                                    <li>Contattato</li>
                                    <li>Da Contattare</li>
                                    <li>In Vendita NOI</li>
                                    <li>In Vendita ALTRI</li>
                                    <li>Altro (con testo libero personalizzato)</li>
                                </ul>
                                <p>Lo stato è <strong>condiviso</strong> tra utente principale e tutti i subutenti del tenant.</p>

                                <h6 class="mt-3">Colore del marker</h6>
                                <p>Ogni stato ha un <strong>colore predefinito</strong> assegnato automaticamente. Puoi sovrascriverlo scegliendo da una palette predefinita di colori principali (rosso, arancio, giallo, verde, azzurro, blu, fucsia, viola): il colore manuale resta memorizzato sul marker finché non lo cambi di nuovo. Il cambio colore è condiviso nel tenant.</p>

                                <h6 class="mt-3">Note</h6>
                                <p>Puoi aggiungere più note allo stesso marker. Ogni nota viene salvata con il <strong>nome e cognome</strong> dell'utente/subutente che l'ha inserita, più <strong>data e ora</strong>. Le note sono visibili a tutti gli utenti del tenant.</p>

                                <h6 class="mt-3">Assegnazione a subutenti</h6>
                                <p>Dal popup del marker puoi assegnare l'immobile a uno o più subutenti. I subutenti assegnati lo vedranno nella sezione <em>Marker assegnati</em> e, se il loro permesso è configurato su "solo marker assegnati", potranno modificare solo questi.</p>
                            </div>
                        </div>
                    </div>

                    <!-- ── REPORT E ANALITICHE ── -->
                    <div class="accordion-item guide-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#g4">
                                <i class="bi bi-table me-2 text-primary"></i>Report e analitiche
                            </button>
                        </h2>
                        <div id="g4" class="accordion-collapse collapse" data-bs-parent="#guideAccordion">
                            <div class="accordion-body small">
                                <h6>Sezione "Marker assegnati"</h6>
                                <p>Vista a tabella focalizzata sugli immobili assegnati. Ogni riga mostra il <strong>pallino colorato</strong> con il colore corrente del marker, tutti i dati catastali, stato, note e assegnazioni. Tutte le colonne (incluso il colore) sono filtrabili. Se hai il permesso di export puoi scaricare la tabella in <strong>CSV o Excel</strong>. Puoi modificare direttamente i campi abilitati dalla tabella senza aprire il popup mappa.</p>

                                <h6 class="mt-3">Sezione "Report in griglia"</h6>
                                <p>Vista generale dell'intero patrimonio del tenant con <strong>filtri avanzati</strong> su tutte le colonne. A differenza di "Marker assegnati", mostra tutti gli immobili del tenant (non solo quelli assegnati al subutente corrente). Utile per analisi comparative, selezioni multi-criteri e panoramiche globali. L'utente principale può anche eliminare singole righe non più necessarie direttamente dalla tabella, con conferma esplicita.</p>

                                <h6 class="mt-3">Sezione Analitiche</h6>
                                <p>Dashboard con <strong>KPI numerici</strong> (totale immobili, distribuzioni, conteggi per stato) e <strong>grafici</strong> (distribuzione per comune, provincia, categoria catastale, genere intestatari, fasce d'età, titolarità). I dati si aggiornano in tempo reale in base agli import effettuati.</p>
                            </div>
                        </div>
                    </div>

                    <!-- ── SUBUTENTI E PERMESSI ── -->
                    <div class="accordion-item guide-item">
                        <h2 class="accordion-header">
                            <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#g5">
                                <i class="bi bi-people me-2 text-primary"></i>Subutenti e permessi
                            </button>
                        </h2>
                        <div id="g5" class="accordion-collapse collapse" data-bs-parent="#guideAccordion">
                            <div class="accordion-body small">
                                <h6>Come creare e invitare un subutente (utente principale)</h6>
                                <ol>
                                    <li>Vai alla sezione <strong>Subutenti</strong> nel menu.</li>
                                    <li>Compila il modulo con <em>Nome</em>, <em>Cognome</em> ed <em>Email</em> del subutente.</li>
                                    <li>Premi <strong>INVITA</strong>: il sistema genera una password temporanea e invia automaticamente una email al subutente con le credenziali di accesso.</li>
                                    <li>Il subutente al primo login dovrà obbligatoriamente impostare una nuova password.</li>
                                </ol>

                                <h6 class="mt-3">Permessi configurabili per ogni subutente</h6>
                                <ul>
                                    <li><strong>Modifica marker</strong>: scegli se il subutente può modificare <em>tutti</em> i marker del tenant o <em>solo quelli assegnati a lui</em>.</li>
                                    <li><strong>Import dati</strong>: se disabilitato, la voce "Import dati" non appare nel menu del subutente.</li>
                                    <li><strong>Analitiche</strong>: abilita o disabilita la sezione analitiche.</li>
                                    <li><strong>Report in griglia</strong>: abilita o disabilita la sezione report.</li>
                                    <li><strong>Export</strong>: abilita o disabilita l'esportazione CSV/Excel dalla sezione "Marker assegnati".</li>
                                </ul>

                                <h6 class="mt-3">Limitazioni dei subutenti</h6>
                                <ul>
                                    <li>I subutenti <strong>non possono mai eliminare</strong> dati o marker, indipendentemente dai permessi configurati. L'eliminazione singola o massiva è riservata all'utente principale o all'amministratore.</li>
                                    <li>Non possono creare altri subutenti.</li>
                                    <li>Vedono solo i dati del tenant cui appartengono (mai i dati di altri tenant).</li>
                                </ul>
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
                                <h6>Numero di telefono</h6>
                                <p>Per rispetto della privacy, il numero di telefono degli intestatari è <strong>nascosto di default</strong> in tutte le visualizzazioni (tabelle, popup mappa, export). Solo se l'amministratore ha attivato esplicitamente il permesso per il tuo tenant il campo diventa visibile.</p>

                                <h6 class="mt-3">Come cambiare la password</h6>
                                <p>Dalla tua area personale (icona utente in alto a destra) puoi accedere alla sezione <strong>Impostazioni account</strong> e modificare la password. Ti verrà chiesta la password attuale per conferma. I subutenti al primo accesso sono reindirizzati obbligatoriamente alla pagina di cambio password.</p>

                                <h6 class="mt-3">Cifratura dei dati</h6>
                                <p>I dati sensibili degli intestatari (nome, cognome, codice fiscale, indirizzo, telefono) vengono salvati in forma <strong>cifrata</strong> nel database e non sono leggibili in chiaro da terzi.</p>

                                <h6 class="mt-3">Isolamento tra tenant</h6>
                                <p>Ogni utente principale (tenant) e i suoi subutenti hanno accesso esclusivo ai propri dati. Non esiste alcun modo per un tenant di visualizzare i dati di un altro tenant.</p>
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
                            'q' => 'Come mi registro su AnalyticsPRO?',
                            'a' => 'Vai alla pagina di registrazione pubblica, compila il modulo con nome, cognome, email e password e invia la richiesta. Il tuo account sarà in stato "in attesa di approvazione". Riceverai una email non appena l\'amministratore avrà approvato il tuo profilo.',
                        ],
                        [
                            'q' => 'Quanto dura la sessione dopo il login?',
                            'a' => 'Se utilizzi l\'opzione "Ricordami", la sessione dura al massimo 10 ore. Senza "Ricordami" la sessione termina alla chiusura del browser. Non è previsto nessun timeout automatico entro le 10 ore.',
                        ],
                        [
                            'q' => 'Ho ricevuto un\'email di invito come subutente: cosa devo fare?',
                            'a' => 'Clicca il link presente nell\'email di invito, inserisci la password temporanea fornita e accedi. Al primo login ti verrà chiesto obbligatoriamente di impostare una nuova password personale prima di poter usare la piattaforma.',
                        ],
                        [
                            'q' => 'Posso vedere i dati di altri utenti o tenant?',
                            'a' => 'No. I dati di ogni tenant sono completamente isolati. Come utente principale vedi solo i tuoi dati e quelli dei tuoi subutenti. Come subutente vedi solo i dati del tenant cui appartieni. Non esiste nessuna sovrapposizione tra tenant diversi.',
                        ],
                        [
                            'q' => 'Quali formati di file posso importare?',
                            'a' => 'Puoi importare file .csv, .xlsx e .xls. Il file deve contenere le colonne catastali attese: Provincia, Comune, Foglio, Particella, Subalterno, Indirizzo, Categoria, Codice Fiscale, Titolarità, Quota, Contatti, Data Nascita. Se sono presenti colonne come Note, Piano, Nome1, Nome2 o Nome3, vengono importate automaticamente nei campi corretti.',
                        ],
                        [
                            'q' => 'Posso chiudere la pagina durante l\'import?',
                            'a' => 'No. Durante l\'import compare un overlay bloccante con barra di avanzamento che mostra lo stato reale dell\'elaborazione. Non chiudere la pagina né il browser fino al completamento, altrimenti l\'importazione potrebbe interrompersi.',
                        ],
                        [
                            'q' => 'Cosa succede se importo lo stesso immobile due volte con intestatario diverso?',
                            'a' => 'AnalyticsPRO rileva il duplicato (stessi Comune + Foglio + Particella + Subalterno) e mostra una finestra di conferma. Puoi scegliere di aggiornare il dato (il vecchio intestatario viene storicizzato) oppure mantenere quello esistente e ignorare il nuovo.',
                        ],
                        [
                            'q' => 'Posso vedere chi era intestatario di un immobile in passato?',
                            'a' => 'Sì. AnalyticsPRO conserva uno storico degli intestatari nel tempo. Ogni aggiornamento non cancella il dato precedente ma lo archivia con la data di sostituzione, così puoi sempre risalire alla storia della titolarità di ogni immobile.',
                        ],
                        [
                            'q' => 'Come posso cambiare il colore di un marker?',
                            'a' => 'Clicca sul marker sulla mappa per aprire il popup, poi scegli il colore da una palette predefinita di colori principali. Il colore è indipendente dallo stato: puoi cambiarlo liberamente in qualsiasi momento e resta memorizzato finché non lo modifichi di nuovo.',
                        ],
                        [
                            'q' => 'Perché un marker cambia colore quando cambio stato?',
                            'a' => 'Ogni stato (Non Interessato, Interessato, Contattato, ecc.) ha un colore predefinito applicato automaticamente. Puoi sempre sovrascrivere questo colore manualmente scegliendolo dalla palette disponibile: il colore manuale ha la precedenza e rimane anche se cambi lo stato in un secondo momento.',
                        ],
                        [
                            'q' => 'Le note sui marker sono private?',
                            'a' => 'No. Le note sono condivise tra l\'utente principale e tutti i subutenti del medesimo tenant. Ogni nota riporta il nome e cognome di chi l\'ha inserita più data e ora di creazione.',
                        ],
                        [
                            'q' => 'Posso assegnare un immobile a più subutenti contemporaneamente?',
                            'a' => 'Sì. Dal popup del marker puoi assegnare l\'immobile a uno o più subutenti. I subutenti assegnati troveranno l\'immobile nella loro sezione "Marker assegnati".',
                        ],
                        [
                            'q' => 'Cos\'è la sezione "Marker assegnati" e come si differenzia dal "Report in griglia"?',
                            'a' => '"Marker assegnati" è una vista a tabella focalizzata sugli immobili assegnati all\'utente corrente (o al subutente). Ogni riga mostra il pallino colorato, tutte le colonne sono filtrabili e puoi modificare direttamente i dati. Il "Report in griglia" invece mostra l\'intero patrimonio del tenant con filtri avanzati, utile per analisi e panoramiche globali.',
                        ],
                        [
                            'q' => 'Come posso esportare i dati?',
                            'a' => 'Dalla sezione "Marker assegnati" (se il permesso export è abilitato) trovi i pulsanti per esportare in CSV o Excel. L\'esportazione tiene conto dei filtri attivi.',
                        ],
                        [
                            'q' => 'Perché non vedo il numero di telefono degli intestatari?',
                            'a' => 'Il numero di telefono è nascosto di default per rispetto della privacy. Solo se l\'amministratore ha attivato esplicitamente il permesso per il tuo tenant il campo diventa visibile in tabelle, popup mappa ed export.',
                        ],
                        [
                            'q' => 'Come cambio la password?',
                            'a' => 'Accedi alla tua area personale (icona utente in alto a destra) e vai su "Impostazioni account". Ti verrà chiesta la password attuale per conferma prima di salvare quella nuova. I subutenti al primo accesso sono reindirizzati obbligatoriamente alla pagina di cambio password.',
                        ],
                        [
                            'q' => 'Posso eliminare un marker come subutente?',
                            'a' => 'No. I subutenti non possono eliminare immobili o marker in nessun caso, indipendentemente dai permessi configurati dall\'utente principale.',
                        ],
                        [
                            'q' => 'Come invito un subutente?',
                            'a' => 'Vai alla sezione "Subutenti" nel menu, compila nome, cognome ed email del subutente e premi INVITA. Il sistema genera automaticamente una password temporanea e invia una email di invito al subutente.',
                        ],
                        [
                            'q' => 'Posso configurare i permessi di un subutente dopo l\'invito?',
                            'a' => 'Sì. Dalla gestione subutenti puoi modificare in qualsiasi momento i permessi: modifica tutti i marker o solo assegnati, accesso all\'import, alle analitiche, al report e all\'export.',
                        ],
                        [
                            'q' => 'Un subutente può vedere le analitiche?',
                            'a' => 'Solo se l\'utente principale ha abilitato il permesso "Analitiche" per quel subutente. In caso contrario la sezione non appare nel menu.',
                        ],
                        [
                            'q' => 'Come filtrare per colore o stato nella tabella?',
                            'a' => 'Nelle sezioni "Marker assegnati" e "Report in griglia" tutte le colonne, incluso il colore e lo stato del marker, sono filtrabili tramite i controlli presenti nell\'intestazione della tabella.',
                        ],
                        [
                            'q' => 'I dati che importo sono al sicuro?',
                            'a' => 'Sì. I dati sensibili degli intestatari (nome, cognome, codice fiscale, indirizzo, telefono) vengono salvati cifrati nel database. Non sono leggibili in chiaro da nessuno al di fuori della piattaforma.',
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
