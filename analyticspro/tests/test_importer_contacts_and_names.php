<?php

declare(strict_types=1);

require __DIR__ . '/../includes/importer.php';

$pass = true;
$errors = [];

$parsed = analyticspro_parse_contacts("3356179530,3351421184 - ,309907108,0309907619");
$expectedPhones = ['3356179530', '3351421184', '309907108', '0309907619'];
if (($parsed['phones'] ?? []) !== $expectedPhones) {
    $pass = false;
    $errors[] = 'Parsing telefoni multipli non corretto: ' . json_encode($parsed['phones'] ?? []);
}

$parsedDup = analyticspro_parse_contacts("3386882344,3386882344 - ,,0309900026");
$expectedDup = ['3386882344', '0309900026'];
if (($parsedDup['phones'] ?? []) !== $expectedDup) {
    $pass = false;
    $errors[] = 'Deduplica telefoni non corretta: ' . json_encode($parsedDup['phones'] ?? []);
}

$name = analyticspro_merge_name_columns([
    'Nome' => 'Maria Anna',
    'Nome1' => 'Maria',
    'Nome2' => 'Anna',
    'Nome3' => 'Luisa',
]);
if ($name !== 'Maria Anna Luisa') {
    $pass = false;
    $errors[] = 'Merge nomi multipli non corretto: ' . $name;
}

$duplicateHeaders = analyticspro_extract_row_values([
    'Contatti' => '3331112222',
    'Contatti DUP 2' => '0333555777',
    'ColonnaVuota 8' => 'IGNORA',
], ['Contatti']);
if ($duplicateHeaders !== ['3331112222', '0333555777']) {
    $pass = false;
    $errors[] = 'Gestione header duplicati non corretta: ' . json_encode($duplicateHeaders);
}

$payload = analyticspro_extract_row_payload([
    'Provincia' => 'BS',
    'Comune' => 'Brescia',
    'Codice Catastale' => 'B157',
    'Foglio' => '10',
    'Particella' => '25',
    'Contatti' => '3386882344,3387124334 - ,,0309900026',
]);
if (($payload['owner']['telefono'] ?? '') !== '3386882344;3387124334;0309900026') {
    $pass = false;
    $errors[] = 'Serializzazione telefoni nel payload non corretta: ' . json_encode($payload['owner']['telefono'] ?? null);
}

if ($pass) {
    echo "PASS: contatti multipli e nomi multipli OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: {$error}\n";
}
exit(1);
