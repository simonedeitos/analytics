<?php

declare(strict_types=1);

require __DIR__ . '/../includes/importer.php';

if (!function_exists('analyticspro_hash')) {
    function analyticspro_hash(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return hash('sha256', $value);
    }
}

$cluster = [
    [
        'id' => 10,
        'user_id' => 77,
        'provincia' => 'MI',
        'comune' => 'Milano',
        'cod_catastale' => '',
        'sezione' => '',
        'foglio' => '10',
        'particella' => '123',
        'subalterno' => '1',
        'indirizzo' => '',
        'categoria' => '',
        'classe' => '',
        'rendita' => '',
        'consistenza' => '',
        'piano' => '',
        'lat' => null,
        'lng' => null,
        'posizione_verificata' => 0,
        'stato' => 'interessato',
        'owners' => [[
            'id' => 100,
            'codice_fiscale_hash' => analyticspro_hash('AAA111'),
            'codice_fiscale' => 'AAA111',
            'quota' => '',
            'titolarita' => '',
            'is_current' => 1,
        ]],
        'notes' => [['id' => 1, 'testo' => 'nota 1']],
        'assignments' => [['subuser_id' => 1]],
        'quota' => '1/2',
        'titolarita' => 'Piena proprietà',
    ],
    [
        'id' => 11,
        'user_id' => 77,
        'provincia' => 'MI',
        'comune' => 'Milano',
        'cod_catastale' => 'F205',
        'sezione' => '',
        'foglio' => '010',
        'particella' => '0123',
        'subalterno' => '0001',
        'indirizzo' => 'Via Roma',
        'categoria' => 'A/2',
        'classe' => '3',
        'rendita' => '100',
        'consistenza' => '4 vani',
        'piano' => '2',
        'lat' => 45.1,
        'lng' => 9.1,
        'posizione_verificata' => 1,
        'stato' => 'contattato',
        'owners' => [[
            'id' => 101,
            'codice_fiscale_hash' => analyticspro_hash('BBB222'),
            'codice_fiscale' => 'BBB222',
            'quota' => '1/4',
            'titolarita' => 'Usufrutto',
            'is_current' => 1,
        ]],
        'notes' => [['id' => 2, 'testo' => 'nota 2']],
        'assignments' => [['subuser_id' => 2]],
        'quota' => '1/4',
        'titolarita' => 'Usufrutto',
    ],
    [
        'id' => 12,
        'user_id' => 77,
        'provincia' => 'MI',
        'comune' => 'Milano',
        'cod_catastale' => 'F205',
        'sezione' => '',
        'foglio' => '10',
        'particella' => '123',
        'subalterno' => '1',
        'indirizzo' => '',
        'categoria' => '',
        'classe' => '',
        'rendita' => '',
        'consistenza' => '',
        'piano' => '',
        'lat' => 45.1,
        'lng' => 9.1,
        'posizione_verificata' => 0,
        'stato' => 'interessato',
        'owners' => [[
            'id' => 102,
            'codice_fiscale_hash' => analyticspro_hash('CCC333'),
            'codice_fiscale' => 'CCC333',
            'quota' => '',
            'titolarita' => '',
            'is_current' => 1,
        ]],
        'notes' => [['id' => 3, 'testo' => 'nota 3']],
        'assignments' => [['subuser_id' => 2], ['subuser_id' => 3]],
        'quota' => '1/4',
        'titolarita' => 'Nuda proprietà',
    ],
];

$preview = analyticspro_preview_duplicate_property_merge($cluster);

$pass = true;
$errors = [];

if ((int) ($preview['keeper']['id'] ?? 0) !== 11) {
    $pass = false;
    $errors[] = 'La property da mantenere deve essere quella con posizione verificata.';
}

if (($preview['absorbed_ids'] ?? []) !== [10, 12]) {
    $pass = false;
    $errors[] = 'Gli id assorbiti attesi sono [10,12].';
}

if (count($preview['owners'] ?? []) !== 3) {
    $pass = false;
    $errors[] = 'Il merge deve mantenere i 3 intestatari del cluster.';
} else {
    $ownersByCf = [];
    foreach ($preview['owners'] as $owner) {
        $ownersByCf[$owner['codice_fiscale']] = $owner;
    }
    if (($ownersByCf['AAA111']['quota'] ?? '') !== '1/2') {
        $pass = false;
        $errors[] = 'La quota dell\'owner AAA111 deve essere ereditata dalla property di origine.';
    }
    if (($ownersByCf['CCC333']['titolarita'] ?? '') !== 'Nuda proprietà') {
        $pass = false;
        $errors[] = 'La titolarità dell\'owner CCC333 deve essere preservata durante il merge.';
    }
}

if (count($preview['notes'] ?? []) !== 3) {
    $pass = false;
    $errors[] = 'Tutte le note del cluster devono essere mantenute.';
}

if (count($preview['assignments'] ?? []) !== 3) {
    $pass = false;
    $errors[] = 'Le assegnazioni duplicate devono essere deduplicate per subuser_id.';
}

if (trim((string) ($preview['keeper']['indirizzo'] ?? '')) !== 'Via Roma') {
    $pass = false;
    $errors[] = 'Il merge deve completare i campi mancanti sulla property mantenuta.';
}

if (strpos((string) ($preview['state_note'] ?? ''), 'Stati differenti') === false || strpos((string) ($preview['summary_note'] ?? ''), 'Unificati 3 record duplicati') === false) {
    $pass = false;
    $errors[] = 'Le note di sistema del merge non sono state costruite correttamente.';
}

if ($pass) {
    echo "PASS: merge duplicati properties OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: {$error}\n";
}
exit(1);
