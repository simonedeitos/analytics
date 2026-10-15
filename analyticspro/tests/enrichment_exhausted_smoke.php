<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/functions.php';
require dirname(__DIR__) . '/includes/importer.php';

$root = sys_get_temp_dir() . '/analyticspro_exhausted_retry_' . getmypid();
define('ANALYTICSPRO_ROOT', $root);
mkdir($root . '/storage/gml', 0775, true);
mkdir($root . '/storage/gml_index', 0775, true);
copy(__DIR__ . '/fixtures/B394_test_ple.gml', $root . '/storage/gml/B394_test_ple.gml');
register_shutdown_function(static function () use ($root): void {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
});

// SQLite esegue le stesse query, traducendo solo gli operatori specifici MySQL.
class ExhaustedRetryTestPDO extends PDO
{
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare(str_replace(['<=>', 'NOW()'], ['IS', 'CURRENT_TIMESTAMP'], $query), $options);
    }
}

function analyticspro_db(): PDO
{
    return $GLOBALS['retry_test_pdo'];
}

function retry_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . "\n");
        exit(1);
    }
}

define('APP_DEBUG', true);
$pdo = new ExhaustedRetryTestPDO('sqlite::memory:');
$GLOBALS['retry_test_pdo'] = $pdo;
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('CREATE TABLE properties (
    id INTEGER PRIMARY KEY, user_id INTEGER, import_batch_id INTEGER,
    provincia TEXT, comune TEXT, cod_catastale TEXT, sezione TEXT,
    foglio TEXT, particella TEXT, lat REAL, lng REAL, posizione_verificata INTEGER,
    coord_source TEXT, enrichment_attempts INTEGER DEFAULT 0,
    enrichment_last_attempt_at TEXT, enrichment_last_error_code TEXT, enrichment_last_error_note TEXT
)');
$insert = $pdo->prepare("INSERT INTO properties
    (id, user_id, import_batch_id, provincia, comune, cod_catastale, sezione, foglio, particella,
     lat, coord_source, enrichment_attempts, enrichment_last_attempt_at, enrichment_last_error_code, enrichment_last_error_note)
    VALUES (?, ?, 7, 'BS', 'Calcinato', 'B394', NULL, '', ?, ?, ?, ?, '2020-01-01', 'old', 'old')");
foreach ([
    [1, 1, '1', null, null, 0],
    [2, 1, '2', null, 'unresolved', 1],
    [3, 1, '3', null, null, ANALYTICSPRO_ENRICH_MAX_ATTEMPTS],
    [4, 1, '4', 45.0, 'gml', 0],
    [5, 2, '2', null, 'unresolved', 3],
    [6, 1, '2', null, null, 2],
] as $row) {
    $insert->execute($row);
}

$expected = "p.lat IS NULL AND (p.coord_source IS NULL OR p.coord_source <> 'unresolved') AND COALESCE(p.enrichment_attempts, 0) < " . ANALYTICSPRO_ENRICH_MAX_ATTEMPTS;
retry_assert(analyticspro_enrichment_recoverable_condition_sql('p') === $expected, 'SQL predefinito invariato');
retry_assert(analyticspro_enrichment_recoverable_condition_sql('p', false) === $expected, 'false esplicito invariato');
retry_assert(analyticspro_enrichment_recoverable_condition_sql('p.', true) === 'p.lat IS NULL', 'true rimuove entrambi i vincoli di esaurimento');
foreach ([false => [1, 6], true => [1, 2, 3, 5, 6]] as $include => $expectedIds) {
    $ids = $pdo->query('SELECT p.id FROM properties p WHERE '
        . analyticspro_enrichment_recoverable_condition_sql('p', (bool) $include) . ' ORDER BY p.id')->fetchAll(PDO::FETCH_COLUMN);
    retry_assert($ids === $expectedIds, 'La condizione include/esclude realmente gli esauriti');
}

$default = analyticspro_enrichment_fetch_unique_parcels($pdo, 0, 25);
retry_assert(array_column($default, 'particella') === ['1', '2'], 'Selezione standard solo recuperabili');
$inclusive = analyticspro_enrichment_fetch_unique_parcels($pdo, 0, 25, 1, true, true);
retry_assert(array_column($inclusive, 'cursor_id') === [1, 2, 3], 'Inclusione esauriti, deduplicazione e isolamento tenant');
retry_assert(analyticspro_enrichment_count_unique_parcels($pdo, 0, 1, true, true, true, 1) === 2, 'Conteggio con cursore');
retry_assert(count(analyticspro_enrichment_fetch_unique_parcels($pdo, 7, 25, null, true, true)) === 3, 'Inclusione esauriti anche per batch');

$statsBefore = analyticspro_fetch_missing_coordinate_stats_for_pdo($pdo, 1);
retry_assert($statsBefore['recoverable'] === 2 && $statsBefore['exhausted'] === 2, 'Badge invariati prima del retry');

// Foglio vuoto: fallimento reale e deterministico, senza richieste ai provider.
$cursor = 0;
$seen = [];
do {
    $result = analyticspro_enrich_batch_coordinates_chunk(0, 1, 1, true, $cursor);
    retry_assert($result['next_cursor'] > $cursor, 'Il cursore deve avanzare anche dopo un fallimento');
    $cursor = $result['next_cursor'];
    $seen[] = $cursor;
    retry_assert(count($seen) <= 3, 'Il passaggio inclusivo deve terminare');
} while (!$result['done']);
retry_assert($seen === [1, 2, 3], 'Ogni particella viene tentata una sola volta nel passaggio');
retry_assert($result['remaining'] === 0 && $result['status'] === 'completed', 'Fine passaggio coerente anche con fallimenti');
$rows = $pdo->query('SELECT * FROM properties ORDER BY id')->fetchAll();
foreach ([0, 1, 2, 5] as $index) {
    retry_assert($rows[$index]['enrichment_attempts'] === 1, 'Nuovo fallimento consuma solo il primo tentativo');
    retry_assert($rows[$index]['coord_source'] === null, 'Nuovo ciclo non bloccato da unresolved');
    retry_assert($rows[$index]['enrichment_last_error_code'] === 'dati_incompleti', 'Errore aggiornato dopo il nuovo tentativo');
}
retry_assert($rows[4]['enrichment_attempts'] === 3 && $rows[4]['coord_source'] === 'unresolved', 'Nessun reset su altro tenant');
retry_assert($rows[3]['lat'] === 45.0 && $rows[3]['enrichment_attempts'] === 0, 'Nessun tentativo su righe già risolte');
retry_assert(count(analyticspro_enrichment_fetch_unique_parcels($pdo, 0, 25, 1)) === 3, 'Restano disponibili i tentativi automatici successivi');
retry_assert(analyticspro_enrichment_fetch_unique_parcels($pdo, 0, 25, 1, true, true, $cursor) === [], 'Il cursore esclude tutti i fallimenti già processati');

// I tentativi standard successivi devono esaurire il nuovo ciclo senza azzerarlo.
analyticspro_enrich_batch_coordinates_chunk(0, 25, 1);
analyticspro_enrich_batch_coordinates_chunk(0, 25, 1);
retry_assert(analyticspro_enrichment_fetch_unique_parcels($pdo, 0, 25, 1) === [], 'Dopo il massimo tentativi il default esclude nuovamente gli esauriti');

// Successo reale tramite il GML fixture locale, senza chiamate esterne.
$insert->execute([7, 3, '147', null, 'unresolved', ANALYTICSPRO_ENRICH_MAX_ATTEMPTS]);
$pdo->exec("UPDATE properties SET foglio = '33' WHERE id = 7");
$success = analyticspro_enrich_batch_coordinates_chunk(0, 25, 3, true);
$saved = $pdo->query('SELECT * FROM properties WHERE id = 7')->fetch();
retry_assert($success['done'] && $success['resolved'] === 1 && $success['unresolved'] === 0, 'Il retry esaurito risolve dal GML locale');
retry_assert(abs($saved['lat'] - 45.5) < 0.001 && abs($saved['lng'] - 10.5) < 0.001, 'Coordinate salvate');
retry_assert($saved['coord_source'] === 'gml_locale' && $saved['enrichment_attempts'] === 0, 'Sorgente e contatore ripuliti dopo successo');
foreach (['enrichment_last_attempt_at', 'enrichment_last_error_code', 'enrichment_last_error_note'] as $column) {
    retry_assert($saved[$column] === null, 'Metadati azzerati dopo successo: ' . $column);
}

echo "PASS: exhausted retry selection, reset, success, tenant scope and finite chunk loop\n";
