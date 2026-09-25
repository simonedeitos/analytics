<?php

declare(strict_types=1);

if (($argv[1] ?? '') === '') {
    $scenarios = ['success', 'rollback'];
    $failed = [];
    foreach ($scenarios as $scenario) {
        $output = [];
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($scenario);
        exec($command . ' 2>&1', $output, $exitCode);
        echo implode("\n", $output) . "\n";
        if ($exitCode !== 0) {
            $failed[] = $scenario;
        }
    }

    if ($failed === []) {
        echo "PASS: import provincia_originale regression OK\n";
        exit(0);
    }

    echo 'FAIL: scenari falliti: ' . implode(', ', $failed) . "\n";
    exit(1);
}

define('APP_DEBUG', true);
define('ANALYTICSPRO_ROOT', dirname(__DIR__));

function analyticspro_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $scenario = (string) ($GLOBALS['analyticspro_test_scenario'] ?? 'success');
    $dbPath = sys_get_temp_dir() . '/analyticspro_import_regression_' . $scenario . '_' . getmypid() . '.sqlite';
    @unlink($dbPath);
    register_shutdown_function(static function () use ($dbPath): void {
        @unlink($dbPath);
    });

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    try {
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
    } catch (Throwable) {
    }
    if (method_exists($pdo, 'sqliteCreateFunction')) {
        $pdo->sqliteCreateFunction('NOW', static fn (): string => '2026-09-25 18:00:00', 0);
    }

    $pdo->exec('CREATE TABLE import_batches (
        id INTEGER PRIMARY KEY,
        user_id INTEGER NOT NULL,
        uploaded_by INTEGER NOT NULL,
        filename TEXT NOT NULL,
        total_rows INTEGER NOT NULL DEFAULT 0,
        processed_rows INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL DEFAULT "processing",
        error_message TEXT NULL,
        completed_at TEXT NULL,
        enrichment_status TEXT NOT NULL DEFAULT "pending",
        enrichment_processed INTEGER NOT NULL DEFAULT 0,
        enrichment_total INTEGER NOT NULL DEFAULT 0,
        enrichment_report TEXT NULL,
        enrichment_sync INTEGER NOT NULL DEFAULT 0
    )');
    $pdo->exec('CREATE TABLE properties (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        import_batch_id INTEGER NULL,
        provincia TEXT NOT NULL,
        provincia_originale TEXT NULL,
        comune TEXT NOT NULL,
        cod_catastale TEXT NOT NULL DEFAULT "",
        sezione TEXT NOT NULL DEFAULT "",
        foglio TEXT NOT NULL,
        particella TEXT NOT NULL,
        subalterno TEXT NOT NULL DEFAULT "",
        indirizzo TEXT NULL,
        civico TEXT NULL,
        categoria TEXT NULL,
        classe TEXT NULL,
        piano TEXT NULL,
        consistenza TEXT NULL,
        superficie TEXT NULL,
        rendita TEXT NULL,
        titolarita TEXT NULL,
        quota TEXT NULL,
        lat REAL NULL,
        lng REAL NULL,
        posizione_verificata INTEGER NOT NULL DEFAULT 0,
        coord_source TEXT NULL,
        stato TEXT NULL,
        stato_personalizzato TEXT NULL,
        colore_marker TEXT NOT NULL DEFAULT "#0d6efd"
    )');
    $pdo->exec('CREATE TABLE property_owners (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        property_id INTEGER NOT NULL,
        tipo TEXT,
        nome_enc TEXT,
        cognome_enc TEXT,
        codice_fiscale_enc TEXT,
        telefono_enc TEXT,
        indirizzo_enc TEXT,
        email_enc TEXT,
        nome_hash TEXT,
        cognome_hash TEXT,
        codice_fiscale_hash TEXT,
        telefono_hash TEXT,
        data_nascita TEXT,
        luogo_nascita_enc TEXT,
        genere TEXT,
        quota TEXT,
        titolarita TEXT,
        is_current INTEGER NOT NULL DEFAULT 1,
        valid_from TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        valid_to TEXT NULL
    )');
    $pdo->exec('CREATE TABLE import_duplicate_conflicts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        import_batch_id INTEGER NOT NULL,
        property_id INTEGER NOT NULL,
        action_taken TEXT NOT NULL,
        resolved_by INTEGER NULL,
        resolved_at TEXT NULL
    )');
    if ($scenario === 'rollback') {
        $pdo->exec('CREATE TABLE property_notes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            property_id INTEGER NOT NULL,
            author_id INTEGER NOT NULL,
            author_name_snapshot TEXT NOT NULL,
            testo TEXT NOT NULL CHECK (LENGTH(testo) <= 10)
        )');
    } else {
        $pdo->exec('CREATE TABLE property_notes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            property_id INTEGER NOT NULL,
            author_id INTEGER NOT NULL,
            author_name_snapshot TEXT NOT NULL,
            testo TEXT NOT NULL
        )');
    }
    $pdo->exec('CREATE TABLE property_assignments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        property_id INTEGER NOT NULL,
        subuser_id INTEGER NOT NULL,
        assigned_by INTEGER NOT NULL,
        assigned_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )');

    $pdo->exec("INSERT INTO import_batches (id, user_id, uploaded_by, filename, total_rows, processed_rows, status) VALUES (1, 1, 1, 'fixture.csv', 1, 0, 'processing')");
    $pdo->exec("INSERT INTO properties (
        id, user_id, import_batch_id, provincia, provincia_originale, comune, cod_catastale, sezione, foglio, particella, subalterno,
        indirizzo, civico, categoria, classe, piano, consistenza, superficie, rendita, titolarita, quota, lat, lng, posizione_verificata,
        coord_source, stato, stato_personalizzato, colore_marker
    ) VALUES (
        10, 1, NULL, 'BS', 'BRESCIA STORICA', 'MONIGA DEL GARDA', 'F373', '', '2', '381', '',
        'Via Roma', '1', 'A/2', '3', NULL, '4 vani', '120', '1000', 'Vecchia', '1/1', NULL, NULL, 0,
        NULL, 'contattato', 'Follow up', '#123456'
    )");
    $pdo->exec("INSERT INTO property_owners (
        property_id, tipo, nome_enc, cognome_enc, codice_fiscale_enc, telefono_enc, indirizzo_enc, email_enc,
        nome_hash, cognome_hash, codice_fiscale_hash, telefono_hash, data_nascita, luogo_nascita_enc, genere, quota, titolarita, is_current
    ) VALUES (
        10, 'persona', 'Mario', 'Rossi', 'RSSMRA80A01H501Z', '3331112222', '', '',
        '" . hash('sha256', 'Mario') . "', '" . hash('sha256', 'Rossi') . "', '" . hash('sha256', 'RSSMRA80A01H501Z') . "', '" . hash('sha256', '3331112222') . "',
        '1980-01-01', 'Brescia', 'M', '1/1', 'Proprieta', 1
    )");

    return $pdo;
}

if (!function_exists('analyticspro_encrypt')) {
    function analyticspro_encrypt(?string $value): ?string
    {
        return $value;
    }
}

if (!function_exists('analyticspro_decrypt')) {
    function analyticspro_decrypt(?string $value): ?string
    {
        return $value;
    }
}

if (!function_exists('analyticspro_hash')) {
    function analyticspro_hash(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return hash('sha256', $value);
    }
}

require ANALYTICSPRO_ROOT . '/includes/functions.php';
require ANALYTICSPRO_ROOT . '/includes/importer.php';

function regression_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$GLOBALS['analyticspro_test_scenario'] = (string) $argv[1];
$scenario = $GLOBALS['analyticspro_test_scenario'];
$pdo = analyticspro_db();

$updateSet = analyticspro_build_property_import_update_set(true, true);
$updateSql = 'UPDATE properties SET ' . implode(', ', $updateSet) . ' WHERE id = :id';
regression_assert(!str_contains($updateSql, 'NULLIF(:provincia_originale'), 'L\'SQL di update import non deve più usare NULLIF su provincia_originale.');
regression_assert(in_array('provincia_originale = :provincia_originale', $updateSet, true), 'L\'update set deve usare il bind diretto di provincia_originale.');

$resolvedNew = analyticspro_import_resolve_provincia_originale_value(['provincia_originale' => 'BRESCIA'], 'BRESCIA STORICA');
regression_assert($resolvedNew === 'BRESCIA', 'Valore provincia_originale non vuoto deve sovrascrivere quello esistente.');
$resolvedPreserved = analyticspro_import_resolve_provincia_originale_value(['provincia_originale' => ''], 'BRESCIA STORICA');
regression_assert($resolvedPreserved === 'BRESCIA STORICA', 'Valore provincia_originale vuoto deve preservare quello esistente.');

$needsNoUpdate = analyticspro_import_property_needs_update(
    ['provincia_originale' => 'BRESCIA STORICA', 'cod_catastale' => 'F373', 'indirizzo' => null, 'civico' => null, 'categoria' => null, 'classe' => null, 'consistenza' => null, 'superficie' => null, 'rendita' => null, 'titolarita' => null, 'quota' => null, 'piano' => null],
    ['provincia_originale' => '', 'cod_catastale' => 'F373', 'indirizzo' => '', 'civico' => '', 'categoria' => '', 'classe' => '', 'consistenza' => '', 'superficie' => '', 'rendita' => '', 'titolarita' => '', 'quota' => '', 'piano' => ''],
    true,
    true,
    false
);
regression_assert($needsNoUpdate === false, 'Provincia_originale vuota non deve forzare un update se il resto è invariato.');

$payload = [
    'tenant_id' => 1,
    'uploaded_by' => 1,
    'uploaded_by_name' => 'Sistema',
    'decisions' => ['property:10' => 'updated'],
    'keep_assignments_on_replace' => true,
    'rows' => [[
        'Provincia' => 'BRESCIA',
        'Comune' => 'MONIGA DEL GARDA',
        'Codice Catastale' => 'F373',
        'Foglio' => '2',
        'Particella' => '381',
        'Subalterno' => '',
        'Indirizzo' => 'Via Roma',
        'Civico' => '1',
        'Categoria' => 'A/2',
        'Classe' => '3',
        'Consistenza' => '4 vani',
        'Superficie' => '120',
        'Rendita' => '1000',
        'Titolarita' => 'Proprieta',
        'Quota' => '1/2',
        'Nome' => 'Luigi',
        'Cognome' => 'Verdi',
        'Codice Fiscale' => 'VRDLGU80A01H501K',
        'Telefono' => '3399990000',
    ]],
];

if ($scenario === 'rollback') {
    try {
        analyticspro_process_import_batch_payload(1, $payload);
        regression_assert(false, 'Lo scenario rollback deve sollevare un errore SQL durante la nota.');
    } catch (Throwable $exception) {
        regression_assert(str_contains($exception->getMessage(), 'CHECK constraint failed'), 'Lo scenario rollback deve fallire sulla nota per verificare il rollback.');
    }

    $property = $pdo->query('SELECT provincia_originale, titolarita, quota, colore_marker FROM properties WHERE id = 10')->fetch();
    regression_assert(($property['provincia_originale'] ?? null) === 'BRESCIA STORICA', 'Il rollback deve ripristinare provincia_originale precedente.');
    regression_assert(($property['titolarita'] ?? null) === 'Vecchia', 'Il rollback deve ripristinare la titolarità precedente del record immobile.');
    regression_assert(($property['quota'] ?? null) === '1/1', 'Il rollback deve ripristinare la quota precedente del record immobile.');
    regression_assert(($property['colore_marker'] ?? null) === '#123456', 'Il rollback deve ripristinare lo stato grafico precedente.');

    $ownerRows = $pdo->query('SELECT codice_fiscale_enc, is_current, valid_to FROM property_owners ORDER BY id ASC')->fetchAll();
    regression_assert(count($ownerRows) === 1, 'Il rollback deve annullare eventuali nuovi proprietari/cointestatari.');
    regression_assert(($ownerRows[0]['codice_fiscale_enc'] ?? null) === 'RSSMRA80A01H501Z', 'Il rollback deve mantenere il proprietario originario.');
    regression_assert((int) ($ownerRows[0]['is_current'] ?? 0) === 1, 'Il rollback deve lasciare corrente il proprietario originario.');
    regression_assert(($ownerRows[0]['valid_to'] ?? null) === null, 'Il rollback deve annullare la chiusura del proprietario originario.');
    regression_assert((int) $pdo->query('SELECT COUNT(*) FROM import_duplicate_conflicts')->fetchColumn() === 0, 'Il rollback deve annullare anche i conflitti import duplicati.');

    $batch = $pdo->query('SELECT status, error_message FROM import_batches WHERE id = 1')->fetch();
    regression_assert(($batch['status'] ?? null) === 'failed', 'Dopo il rollback il batch deve essere marcato failed.');
    regression_assert(trim((string) ($batch['error_message'] ?? '')) !== '', 'Dopo il rollback il batch failed deve registrare il messaggio di errore.');

    echo "PASS: rollback\n";
    exit(0);
}

$result = analyticspro_process_import_batch_payload(1, $payload);
regression_assert((int) ($result['saved_rows'] ?? 0) === 1, 'Lo scenario success deve salvare il gruppo importato.');

$property = $pdo->query('SELECT provincia_originale, titolarita, quota FROM properties WHERE id = 10')->fetch();
regression_assert(($property['provincia_originale'] ?? null) === 'BRESCIA', 'Provincia_originale non vuota deve aggiornare il valore esistente.');
regression_assert(($property['titolarita'] ?? null) === 'Proprieta', 'La titolarità immobile deve aggiornarsi correttamente.');
regression_assert(($property['quota'] ?? null) === '1/2', 'La quota immobile deve aggiornarsi correttamente.');

$ownerRows = $pdo->query('SELECT codice_fiscale_enc, quota, titolarita, is_current FROM property_owners ORDER BY id ASC')->fetchAll();
regression_assert(count($ownerRows) === 2, 'Lo scenario success deve mantenere lo storico e inserire il nuovo cointestatario.');
regression_assert((int) ($ownerRows[0]['is_current'] ?? 0) === 0, 'Il proprietario originario deve essere chiuso nello storico.');
regression_assert(($ownerRows[1]['codice_fiscale_enc'] ?? null) === 'VRDLGU80A01H501K', 'Il nuovo proprietario/cointestatario deve essere inserito correttamente.');
regression_assert(($ownerRows[1]['quota'] ?? null) === '1/2', 'Il nuovo proprietario deve mantenere la quota importata.');
regression_assert(($ownerRows[1]['titolarita'] ?? null) === 'Proprieta', 'Il nuovo proprietario deve mantenere la titolarità importata.');

$noteCount = (int) $pdo->query('SELECT COUNT(*) FROM property_notes')->fetchColumn();
regression_assert($noteCount === 1, 'Lo scenario success deve continuare a scrivere la nota di sostituzione intestatari.');

analyticspro_debug_assert_sql_params_match($updateSql, [
    'import_batch_id' => 1,
    'cod_catastale' => 'F373',
    'indirizzo' => 'Via Roma',
    'civico' => '1',
    'categoria' => 'A/2',
    'classe' => '3',
    'piano' => null,
    'consistenza' => '4 vani',
    'superficie' => '120',
    'rendita' => '1000',
    'titolarita' => 'Proprieta',
    'quota' => '1/2',
    'provincia_originale' => 'BRESCIA',
    'id' => 10,
]);

echo "PASS: success\n";
