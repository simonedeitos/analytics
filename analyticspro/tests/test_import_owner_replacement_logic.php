<?php

declare(strict_types=1);

if (!function_exists('analyticspro_hash')) {
    function analyticspro_hash(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return hash('sha256', $value);
    }
}

require __DIR__ . '/../includes/importer.php';

$pass = true;
$errors = [];

$currentByCfHash = [
    analyticspro_hash('AAA111') => ['id' => 1, 'codice_fiscale' => 'AAA111'],
    analyticspro_hash('BBB222') => ['id' => 2, 'codice_fiscale' => 'BBB222'],
    analyticspro_hash('CCC333') => ['id' => 3, 'codice_fiscale' => 'CCC333'],
];
$incomingByCfHash = [
    analyticspro_hash('AAA111') => ['codice_fiscale' => 'AAA111'],
];
$plan = analyticspro_build_owner_replacement_plan($currentByCfHash, $incomingByCfHash);
if (!$plan['changed']) {
    $pass = false;
    $errors[] = 'Il piano deve segnalare cambio intestatari.';
}
if (count($plan['close_hashes']) !== 2 || count($plan['keep_hashes']) !== 1 || count($plan['insert_hashes']) !== 0) {
    $pass = false;
    $errors[] = 'Il piano 3 vecchi -> 1 nuovo (con continuità su AAA111) non è corretto.';
}

$continuityCurrent = ['nome' => 'Mario', 'telefono' => '3331112222', 'email' => '', 'genere' => 'M'];
$continuityIncoming = ['nome' => '', 'telefono' => '3399990000', 'email' => 'mario@example.it', 'genere' => 'M'];
$merged = analyticspro_merge_import_owner_values($continuityCurrent, $continuityIncoming);
if (($merged['nome'] ?? '') !== 'Mario') {
    $pass = false;
    $errors[] = 'La continuità deve mantenere il nome corrente quando il nuovo è vuoto.';
}
if (($merged['telefono'] ?? '') !== '3399990000' || ($merged['email'] ?? '') !== 'mario@example.it') {
    $pass = false;
    $errors[] = 'La continuità deve aggiornare i campi non vuoti del nuovo intestatario.';
}

$replaceDecision = analyticspro_import_decision_for_group(['property:77' => 'updated'], 77, [5, 6]);
if ($replaceDecision !== 'updated') {
    $pass = false;
    $errors[] = 'La decisione per property key deve essere riconosciuta.';
}
$keepDecision = analyticspro_import_decision_for_group([], 77, [5, 6]);
if ($keepDecision !== 'kept_old') {
    $pass = false;
    $errors[] = 'La decisione di default deve mantenere i dati esistenti.';
}

$missingCfPlan = analyticspro_build_owner_replacement_plan([], []);
$missingCfChanged = analyticspro_import_owner_sets_changed(
    [['id' => 1, 'codice_fiscale' => '']],
    [['id' => 2, 'codice_fiscale' => '']],
    $missingCfPlan,
    [],
    []
);
if (!$missingCfChanged) {
    $pass = false;
    $errors[] = 'Owner senza CF devono essere trattati come set cambiato (richiede decisione di sostituzione).';
}

if (analyticspro_normalize_cadastral_number('00123/A') !== '123/A') {
    $pass = false;
    $errors[] = 'Normalizzazione catastale con zeri iniziali non corretta.';
}
if (analyticspro_normalize_text(' Città d\'Èlite ') !== 'CITTA D ELITE') {
    $pass = false;
    $errors[] = 'Normalizzazione testo (accenti/punteggiatura) non corretta.';
}

$resetValues = analyticspro_import_reset_state_values();
if (!array_key_exists('stato', $resetValues) || !array_key_exists('stato_personalizzato', $resetValues) || $resetValues['stato'] !== null || $resetValues['stato_personalizzato'] !== null || ($resetValues['colore_marker'] ?? '') !== '#2A519F') {
    $pass = false;
    $errors[] = 'Reset stato/colore non coerente con il default richiesto.';
}

if ($pass) {
    echo "PASS: import owner replacement logic OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: $error\n";
}
exit(1);
