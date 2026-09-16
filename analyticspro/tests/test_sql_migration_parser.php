<?php

declare(strict_types=1);

if (!defined('ANALYTICSPRO_ROOT')) {
    define('ANALYTICSPRO_ROOT', dirname(__DIR__));
}

require_once ANALYTICSPRO_ROOT . '/includes/maintenance_service.php';

$sql = file_get_contents(ANALYTICSPRO_ROOT . '/sql/migrations/016_normalize_cadastral_nulls_and_unique.sql');
if (!is_string($sql) || $sql === '') {
    fwrite(STDERR, "FAIL: impossibile leggere la migration 016\n");
    exit(1);
}

$statements = analyticspro_maintenance_parse_sql_statements($sql);
$pass = true;
$errors = [];

if (count($statements) !== 4) {
    $pass = false;
    $errors[] = 'La migration 016 deve produrre 4 statement eseguibili, trovati ' . count($statements) . '.';
}
if (isset($statements[0]) && !str_starts_with($statements[0], 'DROP PROCEDURE IF EXISTS _analyticspro_migration_016')) {
    $pass = false;
    $errors[] = 'Il primo statement deve essere il DROP PROCEDURE iniziale.';
}
if (isset($statements[1]) && (!str_contains($statements[1], 'CREATE PROCEDURE _analyticspro_migration_016()') || !str_contains($statements[1], 'SIGNAL SQLSTATE'))) {
    $pass = false;
    $errors[] = 'Il parser deve mantenere intatto il corpo della CREATE PROCEDURE con delimitatore custom.';
}
if (implode("
", $statements) !== '' && str_contains(implode("
", $statements), 'DELIMITER')) {
    $pass = false;
    $errors[] = 'Le direttive DELIMITER non devono comparire negli statement eseguiti via PDO.';
}
if (isset($statements[2]) && trim($statements[2]) !== 'CALL _analyticspro_migration_016()') {
    $pass = false;
    $errors[] = 'Il terzo statement deve essere la CALL della procedura.';
}
if (isset($statements[3]) && trim($statements[3]) !== 'DROP PROCEDURE IF EXISTS _analyticspro_migration_016') {
    $pass = false;
    $errors[] = 'L’ultimo statement deve ripulire la procedura temporanea.';
}

if ($pass) {
    echo "PASS: parser SQL maintenance OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: {$error}\n";
}
exit(1);
