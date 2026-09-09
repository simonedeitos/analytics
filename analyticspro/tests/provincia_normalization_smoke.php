<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/importer.php';
require_once dirname(__DIR__) . '/includes/cadastral_map.php';

$pass = true;
$errors = [];

$cases = [
    ['BRESCIA', 'BS'],
    ['bs', 'BS'],
    ['Provincia di Brescia', 'BS'],
    ['VERBANO-CUSIO-OSSOLA', 'VB'],
    ['FORLÌ-CESENA', 'FC'],
    ['MONZA E DELLA BRIANZA', 'MB'],
];

foreach ($cases as $idx => $case) {
    [$input, $expected] = $case;
    $normalized = analyticspro_normalize_provincia_sigla($input);
    if (($normalized['sigla'] ?? '') !== $expected) {
        $pass = false;
        $errors[] = 'Normalizzazione fallita per caso #' . ($idx + 1) . ' (' . $input . '): atteso ' . $expected . ', ottenuto ' . json_encode($normalized);
    }
}

$payloadOk = analyticspro_extract_row_payload([
    'Provincia' => 'BRESCIA',
    'Codice Catastale' => 'B157',
    'Comune' => 'Brescia',
    'Foglio' => '10',
    'Particella' => '2',
], 41);
if (($payloadOk['property']['provincia'] ?? '') !== 'BS') {
    $pass = false;
    $errors[] = 'Il payload import deve salvare BRESCIA come BS.';
}
if (($payloadOk['property']['provincia_originale'] ?? '') !== 'BRESCIA') {
    $pass = false;
    $errors[] = 'Il payload deve preservare provincia_originale con il valore grezzo.';
}
if (!empty($payloadOk['warnings'])) {
    $pass = false;
    $errors[] = 'Provincia riconosciuta non deve generare warning.';
}

$payloadUnknown = analyticspro_extract_row_payload([
    'Provincia' => 'XYZWERTY',
    'Comune' => '',
    'Foglio' => '11',
    'Particella' => '3',
], 7);
if (($payloadUnknown['property']['provincia'] ?? '') !== '') {
    $pass = false;
    $errors[] = 'Provincia non riconosciuta non deve essere troncata o forzata.';
}
if (($payloadUnknown['property']['provincia_originale'] ?? '') !== 'XYZWERTY') {
    $pass = false;
    $errors[] = 'Provincia non riconosciuta deve restare in provincia_originale.';
}
if (empty($payloadUnknown['warnings'][0]) || strpos((string) $payloadUnknown['warnings'][0], 'Provincia non riconosciuta') === false) {
    $pass = false;
    $errors[] = 'Provincia non riconosciuta deve generare warning esplicito.';
}
if (strlen((string) ($payloadUnknown['property']['provincia'] ?? '')) !== 2 && empty($payloadUnknown['warnings'])) {
    $pass = false;
    $errors[] = 'Il payload deve produrre provincia lunga 2 oppure warning esplicito.';
}

$payloadFallback = analyticspro_extract_row_payload([
    'Provincia' => 'VERB',
    'Codice Catastale' => 'F471',
    'Comune' => 'Montichiari',
    'Foglio' => '12',
    'Particella' => '4',
], 5);
if (($payloadFallback['property']['provincia'] ?? '') !== 'BS') {
    $pass = false;
    $errors[] = 'Con codice catastale noto la provincia deve essere derivata correttamente.';
}
if (empty($payloadFallback['warnings'])) {
    $pass = false;
    $errors[] = 'La derivazione da fallback deve essere segnalata con warning esplicito.';
}

if ($pass) {
    echo "PASS: provincia normalization smoke OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: {$error}\n";
}
exit(1);
