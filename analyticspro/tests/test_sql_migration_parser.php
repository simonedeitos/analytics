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
$sql017 = file_get_contents(ANALYTICSPRO_ROOT . '/sql/migrations/017_align_provincia_originale_collation.sql');
if (!is_string($sql017) || $sql017 === '') {
    fwrite(STDERR, "FAIL: impossibile leggere la migration 017\n");
    exit(1);
}

$statements = analyticspro_maintenance_parse_sql_statements($sql);
$statements017 = analyticspro_maintenance_parse_sql_statements($sql017);
$pass = true;
$errors = [];


$customSql = <<<'SQL'
-- comment to ignore
INSERT INTO sample(text) VALUES ('semi;colon');
DELIMITER $$
CREATE PROCEDURE demo()
BEGIN
    SELECT 'body;still string', 'token $$ inside string';
END$$
DELIMITER ;
DROP PROCEDURE demo;
SQL;
$customStatements = analyticspro_maintenance_parse_sql_statements($customSql);
if (count($customStatements) !== 3) {
    $pass = false;
    $errors[] = 'Il parser deve ignorare delimitatori presenti nelle stringhe SQL e restituire 3 statement nel fixture custom.';
}
if (isset($customStatements[0]) && trim($customStatements[0]) !== "INSERT INTO sample(text) VALUES ('semi;colon')") {
    $pass = false;
    $errors[] = 'Il parser non deve spezzare il punto e virgola interno alla stringa del primo statement.';
}
if (isset($customStatements[1]) && !str_contains($customStatements[1], "'token $$ inside string'")) {
    $pass = false;
    $errors[] = 'Il parser non deve spezzare il delimitatore custom quando compare dentro una stringa.';
}


$commentSql = <<<'SQL'
/* block comment */
INSERT INTO demo VALUES ('keep -- text'); -- inline comment
/* multi
   line */
INSERT INTO demo VALUES ('keep /* text */');
SQL;
$commentStatements = analyticspro_maintenance_parse_sql_statements($commentSql);
if (count($commentStatements) !== 2) {
    $pass = false;
    $errors[] = 'Il parser deve ignorare commenti block e inline fuori dalle stringhe SQL.';
}
if (isset($commentStatements[0]) && !str_contains($commentStatements[0], "'keep -- text'")) {
    $pass = false;
    $errors[] = 'Il parser non deve rimuovere il testo `--` quando si trova dentro una stringa.';
}
if (isset($commentStatements[1]) && !str_contains($commentStatements[1], "'keep /* text */'")) {
    $pass = false;
    $errors[] = 'Il parser non deve rimuovere il testo `/* */` quando si trova dentro una stringa.';
}


$multilineDelimiterSql = <<<'SQL'
INSERT INTO demo VALUES ('prima riga
DELIMITER $$ dentro stringa
ultima riga');
SELECT 1;
SQL;
$multilineDelimiterStatements = analyticspro_maintenance_parse_sql_statements($multilineDelimiterSql);
if (count($multilineDelimiterStatements) !== 2) {
    $pass = false;
    $errors[] = 'Il parser deve ignorare le pseudo-direttive DELIMITER quando compaiono all’inizio di una riga interna a una stringa multilinea.';
}
if (isset($multilineDelimiterStatements[0]) && !str_contains($multilineDelimiterStatements[0], 'DELIMITER $$ dentro stringa')) {
    $pass = false;
    $errors[] = 'Il parser deve preservare il testo DELIMITER dentro stringhe multilinea.';
}

if (count($statements) < 4) {
    $pass = false;
    $errors[] = 'La migration 016 deve produrre almeno gli statement essenziali per drop/create/call/drop della procedura.';
}
if (isset($statements[0]) && !str_starts_with($statements[0], 'DROP PROCEDURE IF EXISTS _analyticspro_migration_016')) {
    $pass = false;
    $errors[] = 'Il primo statement deve essere il DROP PROCEDURE iniziale.';
}
if (isset($statements[1]) && (!str_contains($statements[1], 'CREATE PROCEDURE _analyticspro_migration_016()') || !str_contains($statements[1], 'SIGNAL SQLSTATE'))) {
    $pass = false;
    $errors[] = 'Il parser deve mantenere intatto il corpo della CREATE PROCEDURE con delimitatore custom.';
}

$invalidDelimiterSql = "SELECT 1
DELIMITER $$
";
try {
    analyticspro_maintenance_parse_sql_statements($invalidDelimiterSql);
    $pass = false;
    $errors[] = 'Il parser deve fallire quando trova SQL pendente prima di un cambio DELIMITER.';
} catch (RuntimeException $exception) {
    if (!str_contains($exception->getMessage(), 'DELIMITER')) {
        $pass = false;
        $errors[] = 'Il parser deve segnalare esplicitamente un errore di cambio DELIMITER non valido.';
    }
}

$unterminatedCustomDelimiterSql = <<<'SQL'
DELIMITER $$
CREATE PROCEDURE broken()
BEGIN
    SELECT 1;
END
SQL;
try {
    analyticspro_maintenance_parse_sql_statements($unterminatedCustomDelimiterSql);
    $pass = false;
    $errors[] = 'Il parser deve fallire quando un blocco con delimitatore custom non viene terminato.';
} catch (RuntimeException $exception) {
    if (!str_contains($exception->getMessage(), 'incompleto')) {
        $pass = false;
        $errors[] = 'Il parser deve segnalare esplicitamente un blocco SQL incompleto con delimitatore custom.';
    }
}

$joinedStatements = implode("\n", $statements);
if ($joinedStatements !== '' && str_contains($joinedStatements, 'DELIMITER')) {
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

if (count($statements017) < 4) {
    $pass = false;
    $errors[] = 'La migration 017 deve produrre almeno drop/create/call/drop della procedura.';
}
if (isset($statements017[1]) && (!str_contains($statements017[1], 'CREATE PROCEDURE _analyticspro_migration_017()') || !str_contains($statements017[1], 'CHARACTER SET'))) {
    $pass = false;
    $errors[] = 'Il parser deve mantenere intatto il corpo della CREATE PROCEDURE della migration 017.';
}
if (isset($statements017[2]) && trim($statements017[2]) !== 'CALL _analyticspro_migration_017()') {
    $pass = false;
    $errors[] = 'Il terzo statement della migration 017 deve essere la CALL della procedura.';
}
if (isset($statements017[3]) && trim($statements017[3]) !== 'DROP PROCEDURE IF EXISTS _analyticspro_migration_017') {
    $pass = false;
    $errors[] = 'L’ultimo statement della migration 017 deve ripulire la procedura temporanea.';
}

if ($pass) {
    echo "PASS: parser SQL maintenance OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: {$error}\n";
}
exit(1);
