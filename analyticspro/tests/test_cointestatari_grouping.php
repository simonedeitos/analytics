<?php

declare(strict_types=1);

require __DIR__ . '/../includes/importer.php';

$pass = true;
$errors = [];

$mainCase = [
    ['id' => 1, 'tenant_id' => 50, 'provincia' => 'MI', 'comune' => 'Milàno', 'cod_catastale' => 'F205', 'sezione' => '', 'foglio' => '0010', 'particella' => '00123/A', 'subalterno' => '0001', 'rendita' => 'R.100 00'],
    ['id' => 2, 'tenant_id' => 50, 'provincia' => 'MI', 'comune' => 'MILANO', 'cod_catastale' => '', 'sezione' => null, 'foglio' => '10', 'particella' => '123/A', 'subalterno' => '1', 'rendita' => 'R.100 00'],
];
$mainGroups = analyticspro_group_records_by_canonical_unit($mainCase);
if (count($mainGroups) !== 1 || count($mainGroups[0]) !== 2) {
    $pass = false;
    $errors[] = 'Stesso immobile con cod_catastale presente/mancante deve produrre un solo gruppo.';
}

$sameRenditaCase = [
    ['id' => 11, 'tenant_id' => 50, 'provincia' => 'MI', 'comune' => 'Milano', 'cod_catastale' => 'F205', 'sezione' => '', 'foglio' => '10', 'particella' => '123', 'subalterno' => '', 'rendita' => 'R.426 08'],
    ['id' => 12, 'tenant_id' => 50, 'provincia' => 'MI', 'comune' => 'MILANO', 'cod_catastale' => '', 'sezione' => '', 'foglio' => '010', 'particella' => '0123', 'subalterno' => '', 'rendita' => 'R.42608'],
];
$sameRenditaGroups = analyticspro_group_records_by_canonical_unit($sameRenditaCase);
if (count($sameRenditaGroups) !== 1 || count($sameRenditaGroups[0]) !== 2) {
    $pass = false;
    $errors[] = 'Subalterno vuoto con rendita uguale deve fondersi.';
}

$differentRenditaCase = [
    ['id' => 21, 'tenant_id' => 50, 'provincia' => 'MI', 'comune' => 'Milano', 'cod_catastale' => 'F205', 'sezione' => '', 'foglio' => '10', 'particella' => '123', 'subalterno' => '', 'rendita' => 'R.100 00'],
    ['id' => 22, 'tenant_id' => 50, 'provincia' => 'MI', 'comune' => 'Milano', 'cod_catastale' => 'F205', 'sezione' => '', 'foglio' => '10', 'particella' => '123', 'subalterno' => '', 'rendita' => 'R.200 00'],
    ['id' => 23, 'tenant_id' => 50, 'provincia' => 'MI', 'comune' => 'Milano', 'cod_catastale' => '', 'sezione' => '', 'foglio' => '10', 'particella' => '123', 'subalterno' => '1', 'rendita' => 'R.100 00'],
];
$differentRenditaGroups = analyticspro_group_records_by_canonical_unit($differentRenditaCase);
if (count($differentRenditaGroups) !== 3) {
    $pass = false;
    $errors[] = 'Subalterno vuoto con rendite diverse deve restare separato.';
}

$emptySubCase = [
    ['id' => 31, 'tenant_id' => 50, 'provincia' => 'MI', 'comune' => 'Milano', 'cod_catastale' => '', 'sezione' => '', 'foglio' => '10', 'particella' => '123', 'subalterno' => '', 'rendita' => 'R.232 41'],
    ['id' => 32, 'tenant_id' => 50, 'provincia' => 'MI', 'comune' => 'Milano', 'cod_catastale' => '', 'sezione' => '', 'foglio' => '10', 'particella' => '123', 'subalterno' => '', 'rendita' => 'R.23241'],
];
$emptySubGroups = analyticspro_group_records_by_canonical_unit($emptySubCase);
if (count($emptySubGroups) !== 1 || count($emptySubGroups[0]) !== 2) {
    $pass = false;
    $errors[] = 'Due record entrambi senza subalterno e con rendita uguale devono fondersi.';
}

$preparedRows = [
    ['tenant_id' => 50, 'provincia' => 'MI', 'comune' => 'Milano', 'cod_catastale' => 'F205', 'sezione' => '', 'foglio' => '10', 'particella' => '123', 'subalterno' => '1', 'rendita' => 'R.100 00', '__payload' => ['property' => ['cod_catastale' => 'F205']]],
    ['tenant_id' => 50, 'provincia' => 'MI', 'comune' => 'MILANO', 'cod_catastale' => '', 'sezione' => '', 'foglio' => '10', 'particella' => '123', 'subalterno' => '1', 'rendita' => 'R.100 00', '__payload' => ['property' => ['cod_catastale' => '']]],
    ['tenant_id' => 50, 'provincia' => 'MI', 'comune' => 'MILANO', 'cod_catastale' => 'G273', 'sezione' => '', 'foglio' => '10', 'particella' => '123', 'subalterno' => '', 'rendita' => 'R.200 00', '__payload' => ['property' => ['cod_catastale' => 'G273']]],
    ['tenant_id' => 50, 'provincia' => 'MI', 'comune' => 'MILANO', 'cod_catastale' => '', 'sezione' => '', 'foglio' => '10', 'particella' => '123', 'subalterno' => '', 'rendita' => 'R.20000', '__payload' => ['property' => ['cod_catastale' => '']]],
];
$backfilled = analyticspro_backfill_import_cod_catastale($preparedRows, 50);
if ($backfilled !== 2 || ($preparedRows[1]['cod_catastale'] ?? '') !== 'F205' || (($preparedRows[1]['__payload']['property']['cod_catastale'] ?? '') !== 'F205')) {
    $pass = false;
    $errors[] = 'Il backfill del cod_catastale nello stesso batch non è stato applicato correttamente.';
}
if (($preparedRows[3]['cod_catastale'] ?? '') !== 'G273') {
    $pass = false;
    $errors[] = 'Il backfill deve valorizzare il codice catastale quando la rendita identifica in modo univoco il subalterno vuoto.';
}

if ($pass) {
    echo "PASS: raggruppamento cointestatari OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: {$error}\n";
}
exit(1);
