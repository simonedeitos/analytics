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
<div class="row justify-content-center">
    <div class="col-12 col-xl-10">
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body p-4">
                <h1 class="h3 mb-3">Guida rapida AnalyticsPRO</h1>
                <ol class="help-steps mb-4">
                    <li>Registrati come utente principale dalla pagina pubblica e attendi l'approvazione dell'amministratore.</li>
                    <li>Dopo l'approvazione esegui il login; opzionalmente puoi usare “Ricordami” per mantenere l'accesso per 10 ore.</li>
                    <li>Importa file CSV/XLSX/XLS con le colonne catastali attese (Provincia, Comune, Foglio, Particella, Subalterno, Indirizzo, Categoria, Codice Fiscale, Titolarità, Quota, Contatti, Data Nascita).</li>
                    <li>Se lo stesso immobile è già presente con intestatario differente, AnalyticsPRO chiede se vuoi mantenere il dato esistente o storicizzarlo e sostituirlo.</li>
                    <li>Durante l'importazione resta visibile un overlay bloccante con barra di avanzamento reale.</li>
                    <li>Nella sezione Mappa clicca un marker per vedere dati immobile e intestatari correnti, cambiare stato, colore, note e assegnazioni.</li>
                    <li>Lo stato imposta un colore predefinito, ma puoi sempre scegliere un colore manuale che resta condiviso nel tenant.</li>
                    <li>I subutenti creati dall'utente principale ricevono password temporanea e devono cambiarla al primo accesso.</li>
                    <li>La vista “Marker assegnati” è focalizzata sui marker del subutente o sui filtri di assegnazione.</li>
                    <li>La vista “Report in griglia” mostra l'intero patrimonio del tenant con filtri avanzati su tutte le colonne.</li>
                    <li>La sezione Analitiche riusa KPI e grafici per contatti, genere, età, provincia, comuni, categoria e titolarità.</li>
                    <li>Se il tenant non ha l'autorizzazione telefono, quel dato resta sempre nascosto in tabella, popup ed export.</li>
                    <?php if ($role === 'user'): ?>
                        <li>Crea e invita subutenti dalla tab dedicata, configurando permessi di import, report, analitiche, export e modifica marker.</li>
                    <?php endif; ?>
                    <?php if ($role === 'admin'): ?>
                        <li>Come admin puoi approvare registrazioni, gestire utenti, configurare SMTP, import ADE e scegliere il tenant da osservare in dashboard.</li>
                    <?php endif; ?>
                </ol>

                <?php if ($role === 'admin'): ?>
                    <div class="alert alert-primary">Sezioni admin-only: configurazione SMTP, import cartografia ADE, approvazione registrazioni e gestione utenti.</div>
                <?php elseif ($role === 'user'): ?>
                    <div class="alert alert-primary">Sezioni utente principale: invito subutenti, configurazione permessi, gestione assegnazioni su tutti i marker del tenant.</div>
                <?php else: ?>
                    <div class="alert alert-primary">Sezioni subutente: accesso limitato ai dati del tuo tenant e ai moduli abilitati dal tuo utente principale.</div>
                <?php endif; ?>

                <?php if ($role === 'subuser'): ?>
                    <div class="mb-4">
                        <h2 class="h5">Permessi attivi per il tuo account</h2>
                        <ul>
                            <li>Import dati: <?= !empty($subPermissions['can_import']) ? 'abilitato' : 'non visibile nel menu' ?></li>
                            <li>Analitiche: <?= !empty($subPermissions['can_view_analytics']) ? 'abilitate' : 'nascoste' ?></li>
                            <li>Report in griglia: <?= !empty($subPermissions['can_view_reports']) ? 'abilitati' : 'nascosti' ?></li>
                            <li>Export marker assegnati: <?= !empty($subPermissions['can_export']) ? 'abilitato' : 'disabilitato' ?></li>
                            <li>Modifica tutti i marker del tenant: <?= !empty($subPermissions['can_edit_all_markers']) ? 'sì' : 'solo marker assegnati a te' ?></li>
                        </ul>
                    </div>
                <?php endif; ?>

                <h2 class="h4 mb-3">FAQ</h2>
                <div class="accordion" id="faqAccordion">
                    <?php
                    $faqs = [
                        'Perché non vedo il numero di telefono?' => 'Il flag privacy can_view_phone è gestito a livello tenant. Se è disabilitato dall\'admin, il telefono viene nascosto ovunque, inclusi export e popup mappa.',
                        'Perché non riesco a importare dati?' => 'Un subutente può importare solo se il permesso can_import è attivo. Verifica anche il formato file e la presenza delle colonne catastali minime.',
                        'Come cambio la password?' => 'I subutenti sono reindirizzati alla pagina di cambio password al primo accesso. Gli altri utenti possono ricevere nuove credenziali dall\'amministratore o aggiornare il proprio account nelle evoluzioni successive.',
                        'Cosa succede se importo due volte lo stesso immobile con intestatario diverso?' => 'AnalyticsPRO rileva il duplicato catastale, mostra un avviso e ti permette di mantenere il vecchio intestatario o storicizzarlo inserendo il nuovo come corrente.',
                        'Come faccio a vedere solo i miei immobili assegnati?' => 'Apri la scheda Marker assegnati. Per i subutenti mostra direttamente le assegnazioni personali.',
                        'Perché un marker cambia colore quando cambio stato?' => 'Ogni stato ha un colore predefinito, applicato automaticamente. Puoi comunque sovrascriverlo manualmente con il color picker.',
                        'Il colore manuale viene perso?' => 'No, resta memorizzato sul marker finché non scegli un nuovo colore o un nuovo aggiornamento stato con colore differente.',
                        'Posso eliminare un marker come subutente?' => 'No. I subutenti non possono eliminare immobili o marker in nessun caso.',
                        'Chi vede le note sui marker?' => 'Le note sono condivise fra utente principale e subutenti del medesimo tenant e riportano autore e timestamp.',
                        'Come funziona l\'approvazione registrazioni?' => 'La registrazione pubblica crea un account pending. Solo l\'admin può approvare o rifiutare dalla dashboard.',
                        'Dove configuro l\'SMTP?' => 'Nella sezione Configurazione admin, con pulsante di test connessione.',
                        'Come funziona l\'import cartografia ADE?' => 'L\'admin carica zip provinciali. Un worker asincrono estrae i file, aggiorna progress/log e prepara il popolamento PostGIS.',
                        'I subutenti vedono i dati di altri tenant?' => 'Mai. Ogni subutente eredita il tenant del parent_user_id.',
                        'Come faccio a invitare un subutente?' => 'L\'utente principale compila nome, cognome ed email nella scheda Subutenti e preme INVITA.',
                        'A cosa serve la pagina Aiuto?' => 'Riepiloga i flussi operativi, le differenze fra sezioni e le limitazioni di privacy/permessi in base al ruolo.',
                        'Come faccio a filtrare colore e stato?' => 'Nelle tabelle vengono creati filtri per colonna, compresi colore e stato, così puoi restringere rapidamente il report.',
                    ];
                    $index = 0;
                    foreach ($faqs as $question => $answer):
                        $index++;
                    ?>
                        <div class="accordion-item">
                            <h2 class="accordion-header" id="faq-heading-<?= $index ?>">
                                <button class="accordion-button <?= $index === 1 ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#faq-collapse-<?= $index ?>">
                                    <?= analyticspro_h($question) ?>
                                </button>
                            </h2>
                            <div id="faq-collapse-<?= $index ?>" class="accordion-collapse collapse <?= $index === 1 ? 'show' : '' ?>" data-bs-parent="#faqAccordion">
                                <div class="accordion-body"><?= analyticspro_h($answer) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php analyticspro_render_footer(); ?>
