<?php

declare(strict_types=1);

if (!defined('ANALYTICSPRO_ROOT')) {
    define('ANALYTICSPRO_ROOT', dirname(__DIR__));
}

require_once ANALYTICSPRO_ROOT . '/includes/importer.php';
require_once ANALYTICSPRO_ROOT . '/includes/duplicate_merge_service.php';

if (!function_exists('analyticspro_hash')) {
    function analyticspro_hash(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return hash('sha256', $value);
    }
}

$records = [
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
        'posizione_verificata' => 1,
        'lat' => 45.1,
        'lng' => 9.1,
    ],
];

$scan = analyticspro_duplicate_merge_scan_records($records, 77, 0, 0);
$preview = analyticspro_preview_duplicate_property_merge($records);
$pass = true;
$errors = [];

if (($scan['total_clusters'] ?? 0) !== 1 || count($scan['clusters'] ?? []) !== 1) {
    $pass = false;
    $errors[] = 'La scansione condivisa deve restituire un unico cluster duplicato.';
} else {
    $servicePreview = $scan['clusters'][0]['preview'] ?? [];
    $public = $scan['clusters'][0]['public'] ?? [];
    if (($servicePreview['keeper']['id'] ?? 0) !== ($preview['keeper']['id'] ?? 0)) {
        $pass = false;
        $errors[] = 'CLI e percorso web devono scegliere lo stesso keeper.';
    }
    if (($servicePreview['absorbed_ids'] ?? []) !== ($preview['absorbed_ids'] ?? [])) {
        $pass = false;
        $errors[] = 'CLI e percorso web devono assorbire gli stessi record duplicati.';
    }
    if (($public['property_ids'] ?? []) !== [10, 11]) {
        $pass = false;
        $errors[] = 'Il payload pubblico del cluster deve preservare gli id property per l’apply batch web.';
    }
    if (($public['owners_count'] ?? 0) !== count($preview['owners'] ?? [])) {
        $pass = false;
        $errors[] = 'Il riepilogo pubblico del cluster deve riflettere lo stesso numero di owners del preview condiviso.';
    }
}

if ($pass) {
    echo "PASS: parity merge service CLI/web OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: {$error}\n";
}
exit(1);
