<?php

declare(strict_types=1);

$root = dirname(__DIR__);
if (!defined('ANALYTICSPRO_ROOT')) {
    define('ANALYTICSPRO_ROOT', $root);
}

require_once $root . '/includes/functions.php';

$source = file_get_contents($root . '/admin/index.php');
if (!is_string($source) || $source === '') {
    fwrite(STDERR, "FAIL: impossibile leggere admin/index.php\n");
    exit(1);
}

$errors = [];
if (!str_contains($source, "analyticspro_table_exists(") || !str_contains($source, "ade_import_jobs")) {
    $errors[] = 'admin/index.php deve verificare l\'esistenza di ade_import_jobs prima del conteggio job.';
}
if (!str_contains($source, 'Tabelle ADE non disponibili in questa installazione.')) {
    $errors[] = 'admin/index.php deve mostrare un messaggio esplicito quando le tabelle ADE non sono disponibili.';
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, role TEXT NOT NULL, status TEXT NOT NULL)');
$pdo->exec("INSERT INTO users (id, role, status) VALUES (1, 'user', 'active'), (2, 'subuser', 'active'), (99, 'admin', 'active')");

if (analyticspro_table_exists($pdo, 'ade_import_jobs')) {
    $errors[] = 'analyticspro_table_exists() deve restituire false se ade_import_jobs non esiste.';
}

$runningJobs = 0;
$hasAdeImportJobsTable = analyticspro_table_exists($pdo, 'ade_import_jobs');
if ($hasAdeImportJobsTable) {
    $runningJobs = (int) $pdo->query("SELECT COUNT(*) FROM ade_import_jobs WHERE status IN ('queued','extracting','importing','verifying')")->fetchColumn();
}
if ($runningJobs !== 0) {
    $errors[] = 'Senza tabella ade_import_jobs il contatore job deve degradare a zero.';
}

$pdo->exec('CREATE TABLE ade_import_jobs (id INTEGER PRIMARY KEY, status TEXT NOT NULL)');
$pdo->exec("INSERT INTO ade_import_jobs (id, status) VALUES (1, 'queued'), (2, 'verifying'), (3, 'completed')");
if (!analyticspro_table_exists($pdo, 'ade_import_jobs')) {
    $errors[] = 'analyticspro_table_exists() deve restituire true quando ade_import_jobs esiste.';
}
$runningJobs = 0;
$hasAdeImportJobsTable = analyticspro_table_exists($pdo, 'ade_import_jobs');
if ($hasAdeImportJobsTable) {
    $runningJobs = (int) $pdo->query("SELECT COUNT(*) FROM ade_import_jobs WHERE status IN ('queued','extracting','importing','verifying')")->fetchColumn();
}
if ($runningJobs !== 2) {
    $errors[] = 'Con la tabella presente il contatore job ADE deve continuare a funzionare.';
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, "FAIL: {$error}\n");
    }
    exit(1);
}

echo "PASS: admin index smoke OK\n";
