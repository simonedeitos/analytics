<?php

declare(strict_types=1);

require __DIR__ . '/../includes/importer.php';

$pass = true;
$errors = [];

$mainCase = [
    ['id' => 1, 'tenant_id' => 50, 'provincia' => 'MI', 'comune' => 'Milàno', 'cod_catastale' => 'F205', 'sezione' => '', 'foglio' => '0010', 'particella' => '00123/A', 'subalterno' => '0001'],
    ['id' => 2, 'tenant_id' => 50, 'provincia' => 'MI', 'comune' => 'MILANO', 'cod_catastale' => '', 'sezione' => null, 'foglio' => '10', 'particella' => '123/A', 'subalterno' => '1'],
];
$mainGroups = analyticspro_group_records_by_canonical_unit($mainCase);
if (count($mainGroups) !== 1 || count($mainGroups[0]) !== 2) {
    $pass = false;
    $errors[] = 'Stesso immobile con cod_catastale presente/mancante deve produrre un solo gruppo.';
}

$singleCandidateCase = [
    ['id' => 11, 'tenant_id' => 50, 'provincia' => 'MI', 'comune' => 'Milano', 'cod_catastale' => 'F205', 'sezione' => '', 'foglio' => '10', 'particella' => '123', 'subalterno' => '1'],
    ['id' => 12, 'tenant_id' => 50, 'provincia' => 'MI', 'comune' => 'MILANO', 'cod_catastale' => '', 'sezione' => '', 'foglio' => '010', 'particella' => '0123', 'subalterno' => ''],
];
$singleCandidateGroups = analyticspro_group_records_by_canonical_unit($singleCandidateCase);
if (count($singleCandidateGroups) !== 1 || count($singleCandidateGroups[0]) !== 2) {
    $pass = false;
    $errors[] = 'Subalterno vuoto con candidato unico deve fondersi con il record valorizzato.';
}

$ambiguousCase = [
    ['id' => 21, 'tenant_id' => 50, 'provincia' => 'MI', 'comune' => 'Milano', 'cod_catastale' => 'F205', 'sezione' => '', 'foglio' => '10', 'particella' => '123', 'subalterno' => '1'],
    ['id' => 22, 'tenant_id' => 50, 'provincia' => 'MI', 'comune' => 'Milano', 'cod_catastale' => 'F205', 'sezione' => '', 'foglio' => '10', 'particella' => '123', 'subalterno' => '2'],
    ['id' => 23, 'tenant_id' => 50, 'provincia' => 'MI', 'comune' => 'Milano', 'cod_catastale' => '', 'sezione' => '', 'foglio' => '10', 'particella' => '123', 'subalterno' => ''],
];
$ambiguousGroups = analyticspro_group_records_by_canonical_unit($ambiguousCase);
if (count($ambiguousGroups) !== 3) {
    $pass = false;
    $errors[] = 'Subalterno vuoto con due candidati valorizzati deve restare isolato.';
}

$emptySubCase = [
    ['id' => 31, 'tenant_id' => 50, 'provincia' => 'MI', 'comune' => 'Milano', 'cod_catastale' => '', 'sezione' => '', 'foglio' => '10', 'particella' => '123', 'subalterno' => ''],
    ['id' => 32, 'tenant_id' => 50, 'provincia' => 'MI', 'comune' => 'Milano', 'cod_catastale' => '', 'sezione' => '', 'foglio' => '10', 'particella' => '123', 'subalterno' => ''],
];
$emptySubGroups = analyticspro_group_records_by_canonical_unit($emptySubCase);
if (count($emptySubGroups) !== 2) {
    $pass = false;
    $errors[] = 'Due record entrambi senza subalterno non devono fondersi.';
}

$preparedRows = [
    ['tenant_id' => 50, 'provincia' => 'MI', 'comune' => 'Milano', 'cod_catastale' => 'F205', 'sezione' => '', 'foglio' => '10', 'particella' => '123', 'subalterno' => '1', '__payload' => ['property' => ['cod_catastale' => 'F205']]],
    ['tenant_id' => 50, 'provincia' => 'MI', 'comune' => 'MILANO', 'cod_catastale' => '', 'sezione' => '', 'foglio' => '10', 'particella' => '123', 'subalterno' => '1', '__payload' => ['property' => ['cod_catastale' => '']]],
];
$backfilled = analyticspro_backfill_import_cod_catastale($preparedRows, 50);
if ($backfilled !== 1 || ($preparedRows[1]['cod_catastale'] ?? '') !== 'F205' || (($preparedRows[1]['__payload']['property']['cod_catastale'] ?? '') !== 'F205')) {
    $pass = false;
    $errors[] = 'Il backfill del cod_catastale nello stesso batch non è stato applicato correttamente.';
}

if ($pass) {
    echo "PASS: raggruppamento cointestatari OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: {$error}\n";
}
exit(1);
