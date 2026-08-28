<?php

declare(strict_types=1);

/**
 * Test: con telefono disattivato, il payload non contiene chiavi telefono.
 *
 * Exit code: 0 = pass, 1 = fail.
 */

function build_owner_payload(array $owner, bool $showPhone): array
{
    $record = [
        'tipo' => $owner['tipo'],
        'nome' => $owner['nome'],
        'cognome' => $owner['cognome'],
        'codice_fiscale' => $owner['codice_fiscale'],
        'indirizzo' => $owner['indirizzo'],
        'email' => $owner['email'],
        'data_nascita' => $owner['data_nascita'],
        'genere' => $owner['genere'],
    ];
    if ($showPhone) {
        $record['telefono'] = $owner['telefono'];
    }
    return $record;
}

$owner = [
    'tipo' => 'persona',
    'nome' => 'Mario',
    'cognome' => 'Rossi',
    'codice_fiscale' => 'RSSMRA80A01F205X',
    'telefono' => '3331234567',
    'indirizzo' => 'Via Roma 1',
    'email' => 'mario@example.com',
    'data_nascita' => '1980-01-01',
    'genere' => 'M',
];

$pass = true;
$errors = [];

$payload = build_owner_payload($owner, true);
if (!array_key_exists('telefono', $payload)) {
    $pass = false;
    $errors[] = 'telefono mancante quando showPhone=true';
}

$payloadNoPhone = build_owner_payload($owner, false);
if (array_key_exists('telefono', $payloadNoPhone)) {
    $pass = false;
    $errors[] = 'telefono presente nel payload quando showPhone=false';
}

if ($pass) {
    echo "PASS: phone visibility OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: $error\n";
}
exit(1);
