<?php

declare(strict_types=1);

if (!defined('ANALYTICSPRO_ROOT')) {
    define('ANALYTICSPRO_ROOT', dirname(__DIR__));
}

require_once ANALYTICSPRO_ROOT . '/includes/maintenance_service.php';

$pass = true;
$errors = [];

if (analyticspro_maintenance_evaluate_migration_014_status(['valid_to' => ['IS_NULLABLE' => 'YES']]) !== 'applied') {
    $pass = false;
    $errors[] = 'La migration 014 deve risultare applicata quando esiste property_owners.valid_to.';
}
if (analyticspro_maintenance_evaluate_migration_014_status([]) !== 'not_applied') {
    $pass = false;
    $errors[] = 'La migration 014 deve risultare non applicata quando la colonna valid_to manca.';
}
if (analyticspro_maintenance_evaluate_migration_015_status(['quota' => [], 'titolarita' => []]) !== 'applied') {
    $pass = false;
    $errors[] = 'La migration 015 deve risultare applicata quando quota e titolarita esistono.';
}
if (analyticspro_maintenance_evaluate_migration_015_status(['quota' => []]) !== 'not_applied') {
    $pass = false;
    $errors[] = 'La migration 015 deve risultare non applicata se manca una delle colonne ownership.';
}
$columnsApplied = [
    'cod_catastale' => ['IS_NULLABLE' => 'NO'],
    'sezione' => ['IS_NULLABLE' => 'NO'],
    'subalterno' => ['IS_NULLABLE' => 'NO'],
];
$indexApplied = [
    ['COLUMN_NAME' => 'user_id', 'NON_UNIQUE' => 0],
    ['COLUMN_NAME' => 'provincia', 'NON_UNIQUE' => 0],
    ['COLUMN_NAME' => 'comune', 'NON_UNIQUE' => 0],
    ['COLUMN_NAME' => 'cod_catastale', 'NON_UNIQUE' => 0],
    ['COLUMN_NAME' => 'sezione', 'NON_UNIQUE' => 0],
    ['COLUMN_NAME' => 'foglio', 'NON_UNIQUE' => 0],
    ['COLUMN_NAME' => 'particella', 'NON_UNIQUE' => 0],
    ['COLUMN_NAME' => 'subalterno', 'NON_UNIQUE' => 0],
];
if (analyticspro_maintenance_evaluate_migration_016_status($columnsApplied, $indexApplied) !== 'applied') {
    $pass = false;
    $errors[] = 'La migration 016 deve risultare applicata con colonne NOT NULL e indice uniq_estremi_catastali corretto.';
}
$columnsNullable = $columnsApplied;
$columnsNullable['subalterno']['IS_NULLABLE'] = 'YES';
if (analyticspro_maintenance_evaluate_migration_016_status($columnsNullable, $indexApplied) !== 'not_applied') {
    $pass = false;
    $errors[] = 'La migration 016 deve risultare non applicata se uno dei campi catastali è ancora nullable.';
}

$indexNonUnique = $indexApplied;
$indexNonUnique[7]['NON_UNIQUE'] = 1;
if (analyticspro_maintenance_evaluate_migration_016_status($columnsApplied, $indexNonUnique) !== 'not_applied') {
    $pass = false;
    $errors[] = 'La migration 016 deve risultare non applicata se l’indice uniq_estremi_catastali non è univoco.';
}
$indexWrong = $indexApplied;
$indexWrong[3]['COLUMN_NAME'] = 'sezione';
if (analyticspro_maintenance_evaluate_migration_016_status($columnsApplied, $indexWrong) !== 'not_applied') {
    $pass = false;
    $errors[] = 'La migration 016 deve risultare non applicata con firma indice diversa da quella attesa.';
}

$collationColumnsApplied = [
    'provincia' => ['CHARACTER_SET_NAME' => 'utf8mb4', 'COLLATION_NAME' => 'utf8mb4_general_ci'],
    'provincia_originale' => ['CHARACTER_SET_NAME' => 'utf8mb4', 'COLLATION_NAME' => 'utf8mb4_general_ci'],
];
if (analyticspro_maintenance_evaluate_migration_017_status($collationColumnsApplied) !== 'applied') {
    $pass = false;
    $errors[] = 'La migration 017 deve risultare applicata quando provincia_originale eredita charset/collation canonici da provincia.';
}

$collationColumnsMismatch = $collationColumnsApplied;
$collationColumnsMismatch['provincia_originale']['COLLATION_NAME'] = 'utf8mb4_unicode_ci';
if (analyticspro_maintenance_evaluate_migration_017_status($collationColumnsMismatch) !== 'not_applied') {
    $pass = false;
    $errors[] = 'La migration 017 deve risultare non applicata se provincia_originale ha collation diversa da provincia.';
}

if (analyticspro_maintenance_evaluate_migration_017_status(['provincia' => $collationColumnsApplied['provincia']]) !== 'not_applied') {
    $pass = false;
    $errors[] = 'La migration 017 deve risultare non applicata se provincia_originale manca.';
}

if ($pass) {
    echo "PASS: stato migrazioni maintenance OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: {$error}\n";
}
exit(1);
