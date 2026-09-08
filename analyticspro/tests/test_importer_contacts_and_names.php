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
    'Piano' => '2',
    'Nato A' => 'MONTICHIARI (BS)',
    'Contatti' => '3386882344,3387124334 - ,,0309900026',
    'Note' => 'Nota importata',
]);
if (($payload['owner']['telefono'] ?? '') !== '3386882344;3387124334;0309900026') {
    $pass = false;
    $errors[] = 'Serializzazione telefoni nel payload non corretta: ' . json_encode($payload['owner']['telefono'] ?? null);
}
if (($payload['property']['piano'] ?? '') !== '2') {
    $pass = false;
    $errors[] = 'Mapping piano non corretto: ' . json_encode($payload['property']['piano'] ?? null);
}
if (($payload['note'] ?? '') !== 'Nota importata') {
    $pass = false;
    $errors[] = 'Mapping note non corretto: ' . json_encode($payload['note'] ?? null);
}
if (($payload['owner']['luogo_nascita'] ?? '') !== 'MONTICHIARI (BS)') {
    $pass = false;
    $errors[] = 'Mapping campo Nato A non corretto: ' . json_encode($payload['owner']['luogo_nascita'] ?? null);
}

$aliasPayload = analyticspro_extract_row_payload([
    'Provincia' => 'BS',
    'Comune' => 'Brescia',
    'Codice Catastale' => 'B157',
    'Foglio' => '11',
    'Particella' => '26',
    'Telefono' => '3330001111',
    'Cellulare' => '3399998888',
    'Email' => 'Mario@Example.it',
    'Mail' => 'mario@example.it',
]);
if (($aliasPayload['owner']['telefono'] ?? '') !== '3330001111;3399998888') {
    $pass = false;
    $errors[] = 'Merge alias telefono non corretto: ' . json_encode($aliasPayload['owner']['telefono'] ?? null);
}
if (($aliasPayload['owner']['email'] ?? '') !== 'mario@example.it') {
    $pass = false;
    $errors[] = 'Normalizzazione alias email non corretta: ' . json_encode($aliasPayload['owner']['email'] ?? null);
}

$birthAliasPayload = analyticspro_extract_row_payload([
    'Provincia' => 'BS',
    'Comune' => 'Brescia',
    'Codice Catastale' => 'B157',
    'Foglio' => '11',
    'Particella' => '26',
    'Luogo Nascita' => 'BRESCIA (BS)',
]);
if (($birthAliasPayload['owner']['luogo_nascita'] ?? '') !== 'BRESCIA (BS)') {
    $pass = false;
    $errors[] = 'Alias "Luogo Nascita" non riconosciuto: ' . json_encode($birthAliasPayload['owner']['luogo_nascita'] ?? null);
}

$reorderedPayload = analyticspro_extract_row_payload([
    'Quota' => '1/2',
    'Data Nascita' => '1980-02-26',
    'Comune' => 'Calcinato',
    'Provincia' => 'BS',
    'Codice Catastale' => 'B394',
    'Foglio' => '34',
    'Particella' => '351',
    'Nato A' => 'DESENZANO DEL GARDA (BS)',
]);
if (($reorderedPayload['property']['quota'] ?? '') !== '1/2') {
    $pass = false;
    $errors[] = 'Mapping quota con colonne riordinate non corretto: ' . json_encode($reorderedPayload['property']['quota'] ?? null);
}
if (($reorderedPayload['owner']['data_nascita'] ?? null) !== '1980-02-26') {
    $pass = false;
    $errors[] = 'Mapping data nascita con colonne riordinate non corretto: ' . json_encode($reorderedPayload['owner']['data_nascita'] ?? null);
}

$invalidQuotaPayload = analyticspro_extract_row_payload([
    'Quota' => '2001-01-01',
    'Comune' => 'Calcinato',
    'Provincia' => 'BS',
    'Codice Catastale' => 'B394',
    'Foglio' => '34',
    'Particella' => '351',
]);
if (($invalidQuotaPayload['property']['quota'] ?? 'x') !== '') {
    $pass = false;
    $errors[] = 'Quota con formato data deve essere ignorata: ' . json_encode($invalidQuotaPayload['property']['quota'] ?? null);
}

$manualCoordinatePayload = analyticspro_extract_row_payload([
    'Comune' => 'Calcinato',
    'Provincia' => 'BS',
    'Codice Catastale' => 'B394',
    'Foglio' => '34',
    'Particella' => '351',
    'Latitudine' => '45.489',
    'Longitudine' => '10.410',
]);
if (abs((float) ($manualCoordinatePayload['property']['lat'] ?? 0) - 45.489) > 0.000001 || abs((float) ($manualCoordinatePayload['property']['lng'] ?? 0) - 10.410) > 0.000001) {
    $pass = false;
    $errors[] = 'Coordinate manuali non mappate correttamente: ' . json_encode($manualCoordinatePayload['property']);
}

if ($pass) {
    echo "PASS: contatti multipli e nomi multipli OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: {$error}\n";
}
exit(1);
