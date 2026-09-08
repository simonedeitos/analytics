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

function group_by_unit(array $owners): array
{
    $groups = [];
    foreach ($owners as $owner) {
        $codCatastale = strtoupper(trim($owner['cod_catastale'] ?? ''));
        $comune = strtoupper(trim($owner['comune'] ?? ''));
        $comuneKey = $codCatastale !== '' ? ('COD:' . $codCatastale) : ('COM:' . $comune);
        $key = implode('|', [
            strtoupper(trim($owner['provincia'] ?? '')),
            $comuneKey,
            $comune,
            strtoupper(trim($owner['sezione'] ?? '')),
            strtoupper(trim($owner['foglio'] ?? '')),
            strtoupper(trim($owner['particella'] ?? '')),
            strtoupper(trim($owner['subalterno'] ?? '')),
        ]);
        $groups[$key][] = $owner;
    }
    return array_values($groups);
}

$owners = [
    ['provincia' => 'MI', 'cod_catastale' => 'F205', 'comune' => 'Milano', 'sezione' => '', 'foglio' => '10', 'particella' => '200', 'subalterno' => 'A', 'nome' => 'Mario'],
    ['provincia' => 'MI', 'cod_catastale' => 'F205', 'comune' => 'MILANO', 'sezione' => '', 'foglio' => '10', 'particella' => '200', 'subalterno' => 'A', 'nome' => 'Lucia'],
    ['provincia' => 'MI', 'cod_catastale' => 'F205', 'comune' => 'Milano', 'sezione' => '', 'foglio' => '10', 'particella' => '200', 'subalterno' => 'B', 'nome' => 'Paolo'],
    ['provincia' => 'BS', 'cod_catastale' => 'B157', 'comune' => 'Brescia', 'sezione' => '', 'foglio' => '10', 'particella' => '200', 'subalterno' => 'A', 'nome' => 'Gianni'],
];

$groups = group_by_unit($owners);

$pass = true;
$errors = [];

if (count($groups) !== 3) {
    $pass = false;
    $errors[] = 'Attesi 3 gruppi, trovati ' . count($groups);
}

$groupSizes = array_map('count', $groups);
sort($groupSizes);
if ($groupSizes !== [1, 1, 2]) {
    $pass = false;
    $errors[] = 'Dimensioni gruppi attese [1,1,2], trovate ' . implode(',', $groupSizes);
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

    $mixedComuneGroupFound = false;
    foreach ($groups as $group) {
        $names = array_column($group, 'nome');
        sort($names);
        if ($names === ['Gianni', 'Lucia', 'Mario']) {
            $mixedComuneGroupFound = true;
            break;
        }
    }
    if ($mixedComuneGroupFound) {
        $pass = false;
        $errors[] = 'Proprietà con comune diverso non devono essere accorpate nello stesso gruppo';
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
