<?php

declare(strict_types=1);

if (($argv[1] ?? '') === '') {
    $scenarios = ['with_optional', 'without_optional'];
    $errors = [];
    foreach ($scenarios as $scenario) {
        $output = [];
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__FILE__) . ' ' . escapeshellarg($scenario);
        exec($cmd . ' 2>&1', $output, $exitCode);
        echo implode("\n", $output) . "\n";
        if ($exitCode !== 0) {
            $errors[] = $scenario;
        }
    }

    if ($errors === []) {
        echo "PASS: missing coordinate stats smoke OK\n";
        exit(0);
    }

    echo 'FAIL: scenari falliti: ' . implode(', ', $errors) . "\n";
    exit(1);
}

define('APP_DEBUG', true);

function analyticspro_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $scenario = (string) ($GLOBALS['analyticspro_test_scenario'] ?? 'with_optional');
    $dbPath = sys_get_temp_dir() . '/analyticspro_missing_stats_' . $scenario . '_' . getmypid() . '.sqlite';
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

    $pdo->exec('CREATE TABLE users (
        id INTEGER PRIMARY KEY,
        nome TEXT NOT NULL,
        cognome TEXT NOT NULL,
        email TEXT NOT NULL,
        role TEXT NOT NULL
    )');

    $columns = [
        'id INTEGER PRIMARY KEY AUTOINCREMENT',
        'user_id INTEGER NOT NULL',
        'import_batch_id INTEGER NOT NULL DEFAULT 0',
        'provincia TEXT NOT NULL',
        'comune TEXT NOT NULL',
        "cod_catastale TEXT NOT NULL DEFAULT ''",
        'sezione TEXT NULL',
        'foglio TEXT NOT NULL',
        'particella TEXT NOT NULL',
        'lat REAL NULL',
        'lng REAL NULL'
    ];
    if ($scenario === 'with_optional') {
        $columns[] = 'coord_source TEXT NULL';
        $columns[] = 'enrichment_attempts INTEGER NULL DEFAULT 0';
        $columns[] = 'enrichment_last_attempt_at TEXT NULL';
        $columns[] = 'enrichment_last_error_code TEXT NULL';
        $columns[] = 'enrichment_last_error_note TEXT NULL';
    }

    $pdo->exec('CREATE TABLE properties (' . implode(', ', $columns) . ')');
    $pdo->exec("INSERT INTO users (id, nome, cognome, email, role) VALUES
        (1, 'Mario', 'Rossi', 'mario@example.test', 'user'),
        (2, 'Luigi', 'Bianchi', 'luigi@example.test', 'user'),
        (99, 'Admin', 'Root', 'admin@example.test', 'admin')
    ");

    $baseRows = [
        [1, 0, 'BS', 'Calcinato', 'B394', null, '34', '351', null, null],
        [1, 0, 'BS', 'Calcinato', 'B394', null, '34', '351', null, null],
        [1, 0, 'BS', 'Calcinato', 'B394', null, '35', '999', null, null],
        [1, 0, 'BS', 'Calcinato', 'B394', null, '40', '777', 45.1, 10.2],
        [2, 0, 'MI', 'Milano', 'F205', null, '12', '500', null, null]
    ];

    if ($scenario === 'with_optional') {
        $stmt = $pdo->prepare('INSERT INTO properties (user_id, import_batch_id, provincia, comune, cod_catastale, sezione, foglio, particella, lat, lng, coord_source, enrichment_attempts, enrichment_last_attempt_at, enrichment_last_error_code, enrichment_last_error_note) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL)');
        foreach ($baseRows as $index => $row) {
            $coordSource = null;
            $attempts = 0;
            if ($index === 2) {
                $coordSource = 'unresolved';
                $attempts = 3;
            }
            $stmt->execute(array_merge($row, [$coordSource, $attempts]));
        }
        return $pdo;
    }

    $stmt = $pdo->prepare('INSERT INTO properties (user_id, import_batch_id, provincia, comune, cod_catastale, sezione, foglio, particella, lat, lng) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($baseRows as $row) {
        $stmt->execute($row);
    }

    return $pdo;
}

require dirname(__DIR__) . '/includes/importer.php';

function smoke_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function smoke_assert_stats(array $stats, int $total, int $recoverable, int $uniqueParcels, int $uniqueRecoverable, string $label): void
{
    smoke_assert((int) ($stats['total'] ?? -1) === $total, $label . ': total inatteso');
    smoke_assert((int) ($stats['recoverable'] ?? -1) === $recoverable, $label . ': recoverable inatteso');
    smoke_assert((int) ($stats['unique_parcels'] ?? -1) === $uniqueParcels, $label . ': unique_parcels inatteso');
    smoke_assert((int) ($stats['unique_parcels_recoverable'] ?? -1) === $uniqueRecoverable, $label . ': unique_parcels_recoverable inatteso');
    smoke_assert((int) ($stats['recoverable'] ?? 0) <= (int) ($stats['total'] ?? 0), $label . ': recoverable non può superare total');
    smoke_assert((int) ($stats['exhausted'] ?? -1) === $total - $recoverable, $label . ': exhausted deve essere total - recoverable');
}

$GLOBALS['analyticspro_test_scenario'] = (string) $argv[1];
$scenario = $GLOBALS['analyticspro_test_scenario'];
$pdo = analyticspro_db();

$driver = strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
smoke_assert($driver === 'sqlite', 'Il test smoke deve usare un PDO reale SQLite nel sandbox');

$tenantStats = analyticspro_fetch_missing_coordinate_stats_for_pdo($pdo, 1);
$globalStats = analyticspro_fetch_missing_coordinate_stats_for_pdo($pdo, null);
$tenantPayload = analyticspro_missing_coordinates_stats_payload($pdo, false, 1);
$adminPayload = analyticspro_missing_coordinates_stats_payload($pdo, true, null);

if ($scenario === 'with_optional') {
    smoke_assert_stats($tenantStats, 3, 2, 2, 1, 'tenant with optional');
    smoke_assert_stats($globalStats, 4, 3, 3, 2, 'global with optional');
} else {
    smoke_assert_stats($tenantStats, 3, 3, 2, 2, 'tenant without optional');
    smoke_assert_stats($globalStats, 4, 4, 3, 3, 'global without optional');
}

smoke_assert(($tenantPayload['ok'] ?? false) === true, 'Payload tenant deve avere ok=true');
smoke_assert(($tenantPayload['scope'] ?? '') === 'tenant', 'Payload tenant deve avere scope=tenant');
smoke_assert((int) (($tenantPayload['stats']['total'] ?? -1)) === (int) $tenantStats['total'], 'Payload tenant deve riusare le stats tenant');
smoke_assert((int) (($tenantPayload['stats']['unique_parcels'] ?? -1)) === (int) $tenantStats['unique_parcels'], 'Payload tenant deve esporre unique_parcels tenant');
smoke_assert((int) (($tenantPayload['stats']['total'] ?? 0)) === 3, 'Il percorso non-admin deve restare isolato sul tenant corrente');

smoke_assert(($adminPayload['ok'] ?? false) === true, 'Payload admin deve avere ok=true');
smoke_assert(($adminPayload['scope'] ?? '') === 'admin', 'Payload admin deve avere scope=admin');
smoke_assert((int) (($adminPayload['stats']['total'] ?? -1)) === (int) $globalStats['total'], 'Payload admin deve esporre il totale globale');
smoke_assert(count($adminPayload['tenants'] ?? []) >= 2, 'Payload admin deve includere il dettaglio per tenant');

$tenantRowsById = [];
foreach (($adminPayload['tenants'] ?? []) as $row) {
    $tenantRowsById[(int) ($row['tenant_id'] ?? 0)] = $row;
}
smoke_assert(isset($tenantRowsById[1]), 'Il dettaglio admin deve includere il tenant 1');
smoke_assert(isset($tenantRowsById[2]), 'Il dettaglio admin deve includere il tenant 2');
smoke_assert((int) ($tenantRowsById[1]['total'] ?? -1) === 3, 'Il totale admin del tenant 1 deve restare isolato');
smoke_assert((int) ($tenantRowsById[2]['total'] ?? -1) === 1, 'Il totale admin del tenant 2 deve restare isolato');

try {
    analyticspro_debug_assert_sql_params_match(
        'SELECT COUNT(*) FROM properties WHERE user_id = :tenant_id AND user_id = :tenant_id',
        ['tenant_id' => 1]
    );
    smoke_assert(false, 'La guardia debug deve rifiutare placeholder duplicati');
} catch (RuntimeException $exception) {
    smoke_assert(str_contains($exception->getMessage(), 'duplicato'), 'La guardia debug deve segnalare il placeholder duplicato');
}

try {
    analyticspro_debug_assert_sql_params_match(
        'SELECT COUNT(*) FROM properties WHERE user_id = :tenant_id',
        ['tenant_id' => 1, 'extra' => 2]
    );
    smoke_assert(false, 'La guardia debug deve rifiutare parametri in eccesso');
} catch (RuntimeException $exception) {
    smoke_assert(str_contains($exception->getMessage(), 'eccesso'), 'La guardia debug deve segnalare i parametri in eccesso');
}

$importPage = (string) file_get_contents(dirname(__DIR__) . '/importa.php');
smoke_assert(str_contains($importPage, 'data-missing-coordinates-stats-endpoint'), 'Importa deve continuare a puntare all\'endpoint delle statistiche');

$stmt = $pdo->prepare('SELECT COUNT(*) FROM properties WHERE lat IS NULL AND user_id = :tenant_id');
$stmt->execute(['tenant_id' => 1]);
smoke_assert((int) $stmt->fetchColumn() === 3, 'La query PDO reale deve eseguire correttamente il filtro tenant');

echo 'PASS: ' . $scenario . "\n";
