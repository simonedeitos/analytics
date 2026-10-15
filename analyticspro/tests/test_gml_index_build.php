<?php

declare(strict_types=1);

// Eseguire con: php analyticspro/tests/test_gml_index_build.php
function analyticspro_json(array $payload, int $status): never
{
    throw new RuntimeException($payload['error'], $status);
}

$source = file_get_contents(dirname(__DIR__) . '/api/admin/gml_index_build.php');
$start = strpos($source, '$inputBelfiore =');
$end = strpos($source, '$pdo       =', $start);
if ($start === false || $end === false) {
    throw new RuntimeException('Validazione POST Belfiore non trovata.');
}
$validation = substr($source, $start, $end - $start);
$validate = static function ($input) use ($validation): array {
    $_POST = ['belfiore' => $input];
    eval($validation);
    return [$rawBelfiore, $jobScope];
};

foreach ([
    ['ALL', 'ALL', 'tutti i comuni del catalogo'],
    [' all ', 'ALL', 'tutti i comuni del catalogo'],
    [' h501 ', 'H501', '1 comuni'],
    [' b394, H501,b394, ,c123 ', 'B394,H501,C123', '3 comuni'],
] as [$input, $expected, $scope]) {
    if ($validate($input) !== [$expected, $scope]) {
        throw new RuntimeException('Normalizzazione/deduplica errata: ' . $input);
    }
}
$codes = [];
for ($i = 0; $i < 2000; $i++) {
    $codes[] = chr(65 + intdiv($i, 1000)) . sprintf('%03d', $i % 1000);
}
$normalized = implode(',', $codes);
if ($validate($normalized) !== [$normalized, '2000 comuni']) {
    throw new RuntimeException('Il limite di 2000 codici deve essere accettato senza troncamento.');
}
foreach ([
    ['', 'almeno un codice'],
    [' , , ', 'almeno un codice'],
    ['B394,INVALID,H501', 'non valido'],
    ['B394,0', 'non valido'],
    ['ALL,B394', 'non valido'],
    ['B394,H50', 'non valido'],
    [$normalized . ',C001', 'massimo 2000'],
    [str_repeat('B394,', 2000) . 'B394', 'massimo 2000'],
    [str_repeat(' ', 32001), 'troppo grande'],
    [['B394'], 'non valido'],
] as [$input, $error]) {
    try {
        $validate($input);
    } catch (RuntimeException $e) {
        if ($e->getCode() === 400 && str_contains($e->getMessage(), $error)) {
            continue;
        }
        throw $e;
    }
    throw new RuntimeException('Richiesta non valida accettata.');
}
require_once dirname(__DIR__) . '/includes/maintenance_service.php';
$migration = file_get_contents(dirname(__DIR__) . '/sql/migrations/020_expand_gml_index_job_belfiore.sql');
$statements = analyticspro_maintenance_parse_sql_statements($migration);
if (count($statements) !== 1 || !str_contains($statements[0], 'MODIFY COLUMN belfiore TEXT NOT NULL')) {
    throw new RuntimeException('Migration 020: la lista deve essere persistita senza troncamento.');
}
echo "PASS: GML POST Belfiore singolo/ALL/liste, deduplica, limiti, errori e migration\n";
