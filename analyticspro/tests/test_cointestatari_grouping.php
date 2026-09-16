<?php

declare(strict_types=1);

/**
 * Test: raggruppamento cointestatari per unità immobiliare.
 *
 * Due intestatari con stesso comune+sezione+foglio+particella+subalterno
 * devono finire in una sola scheda; stesso foglio+particella ma subalterni
 * diversi restano separati.
 *
 * Exit code: 0 = pass, 1 = fail.
 */

function normalize_text(string $value): string
{
    $value = strtoupper(trim($value));
    $value = strtr($value, ['À' => 'A', 'È' => 'E', 'É' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U']);
    $value = preg_replace('/[^A-Z0-9\/]+/', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}

function normalize_cadastral_number(string $value): string
{
    $value = strtoupper(trim($value));
    $value = preg_replace('/\s+/', '', $value) ?? $value;
    if ($value === '') {
        return '';
    }
    if (preg_match('/^([0-9]+)(.*)$/', $value, $matches) !== 1) {
        return $value;
    }

    $number = ltrim((string) ($matches[1] ?? ''), '0');
    if ($number === '') {
        $number = '0';
    }

    return $number . (string) ($matches[2] ?? '');
}

function group_by_unit(array $owners): array
{
    $groups = [];
    foreach ($owners as $index => $owner) {
        $codCatastale = normalize_text((string) ($owner['cod_catastale'] ?? ''));
        $comune = normalize_text((string) ($owner['comune'] ?? ''));
        $subalterno = normalize_cadastral_number((string) ($owner['subalterno'] ?? ''));
        $comuneKey = $codCatastale !== '' ? ('COD:' . $codCatastale) : ('COM:' . $comune);
        $subalternoKey = $subalterno !== '' ? $subalterno : ('SUB:__NONE__#' . (string) ($owner['id'] ?? $index));
        $tenantId = (string) ($owner['tenant_id'] ?? $owner['user_id'] ?? '');
        $key = implode('|', [
            normalize_text((string) ($owner['provincia'] ?? '')),
            $comuneKey,
            normalize_text((string) ($owner['sezione'] ?? '')),
            normalize_cadastral_number((string) ($owner['foglio'] ?? '')),
            normalize_cadastral_number((string) ($owner['particella'] ?? '')),
            $subalternoKey,
            $tenantId,
        ]);
        $groups[$key][] = $owner;
    }
    return array_values($groups);
}

$owners = [
    ['id' => 1, 'tenant_id' => 50, 'provincia' => 'mi', 'cod_catastale' => 'f205', 'comune' => 'Milàno', 'sezione' => '', 'foglio' => '0010', 'particella' => '00123/A', 'subalterno' => '0001', 'nome' => 'Mario'],
    ['id' => 2, 'tenant_id' => 50, 'provincia' => 'MI', 'cod_catastale' => 'F205', 'comune' => 'MILANO!!', 'sezione' => null, 'foglio' => '10', 'particella' => '123/A', 'subalterno' => '1', 'nome' => 'Lucia'],
    ['id' => 3, 'tenant_id' => 50, 'provincia' => 'MI', 'cod_catastale' => 'F205', 'comune' => 'Milano', 'sezione' => '', 'foglio' => '10', 'particella' => '123/A', 'subalterno' => '', 'nome' => 'Paolo'],
    ['id' => 4, 'tenant_id' => 50, 'provincia' => 'MI', 'cod_catastale' => 'F205', 'comune' => 'Milano', 'sezione' => '', 'foglio' => '10', 'particella' => '123/A', 'subalterno' => null, 'nome' => 'Gianni'],
    ['id' => 5, 'tenant_id' => 99, 'provincia' => 'MI', 'cod_catastale' => 'F205', 'comune' => 'Milano', 'sezione' => '', 'foglio' => '10', 'particella' => '123/A', 'subalterno' => '1', 'nome' => 'Altro tenant'],
];

$groups = group_by_unit($owners);

$pass = true;
$errors = [];

if (count($groups) !== 4) {
    $pass = false;
    $errors[] = 'Attesi 4 gruppi, trovati ' . count($groups);
}

$groupSizes = array_map('count', $groups);
sort($groupSizes);
if ($groupSizes !== [1, 1, 1, 2]) {
    $pass = false;
    $errors[] = 'Dimensioni gruppi attese [1,1,1,2], trovate ' . implode(',', $groupSizes);
}

$subAGroup = null;
foreach ($groups as $group) {
    if (count($group) === 2) {
        $subAGroup = $group;
    }
}
if ($subAGroup === null) {
    $pass = false;
    $errors[] = 'Nessun gruppo da 2 cointestatari trovato';
} else {
    $names = array_column($subAGroup, 'nome');
    sort($names);
    if ($names !== ['Lucia', 'Mario']) {
        $pass = false;
        $errors[] = 'Gruppo da 2 contiene ' . implode(',', $names) . ' invece di Mario,Lucia';
    }

    if (in_array('Altro tenant', $names, true)) {
        $pass = false;
        $errors[] = 'Tenant diversi non devono essere accorpati nello stesso gruppo';
    }
}

if ($pass) {
    echo "PASS: raggruppamento cointestatari OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: $error\n";
}
exit(1);
