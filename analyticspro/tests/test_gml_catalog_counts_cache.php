<?php

declare(strict_types=1);

/**
 * Test: cache conteggi catalogo GML (catalogo_counts.json).
 *
 * Verifica che l'elenco catalogo usato dal GET di api/admin/gml_catalog.php
 * legga i conteggi solo dalla cache, senza aprire SQLite né _fogli.json.
 *
 * Eseguire con:  php analyticspro/tests/test_gml_catalog_counts_cache.php
 * Exit code: 0 = pass, 1 = fail.
 */

if (!defined('ANALYTICSPRO_ROOT')) {
    define('ANALYTICSPRO_ROOT', dirname(__DIR__));
}
if (!function_exists('analyticspro_db')) {
    function analyticspro_db(): never
    {
        throw new RuntimeException('analyticspro_db() non disponibile in modalità test');
    }
}

require_once dirname(__DIR__) . '/includes/gml_stream_parser.php';
require_once dirname(__DIR__) . '/includes/geo_centroid.php';
require_once dirname(__DIR__) . '/includes/gml_catalog.php';

$errors = [];
function counts_assert(bool $cond, string $msg): void
{
    global $errors;
    if (!$cond) {
        $errors[] = $msg;
    }
}

$gmlDir = analyticspro_gml_dir();
$indexDir = analyticspro_gml_index_dir();
@mkdir($gmlDir, 0775, true);
@mkdir($indexDir, 0775, true);

$code = 'Z902';
$gmlFile = $gmlDir . '/' . $code . '_TEST_COUNTS_ple.gml';
$dbPath = $indexDir . '/' . $code . '.sqlite';
$fogliPath = $indexDir . '/' . $code . '_fogli.json';
$countsPath = analyticspro_gml_counts_cache_path();
$catalogPath = $indexDir . '/catalogo.json';

$catalogBackup = is_file($catalogPath) ? file_get_contents($catalogPath) : null;
$countsBackup = is_file($countsPath) ? file_get_contents($countsPath) : null;

try {
    counts_assert(
        $countsPath === $indexDir . '/catalogo_counts.json',
        'Percorso cache conteggi inatteso: ' . $countsPath
    );

    file_put_contents($gmlFile, '<?xml version="1.0" encoding="UTF-8"?><root/>');
    touch($gmlFile, time() - 60);

    $db = new SQLite3($dbPath);
    $db->exec('CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT)');
    $db->exec("INSERT INTO meta (key, value) VALUES ('count', '42'), ('complete', '1')");
    $db->close();
    file_put_contents($fogliPath, json_encode(['000100' => '1', '000200' => '2', '000300' => '3']));
    clearstatcache();

    analyticspro_gml_build_catalog(true);

    // 1. Cache assente: ok con conteggi a 0/false.
    @unlink($countsPath);
    counts_assert(analyticspro_gml_load_counts_cache() === null, 'Cache assente deve restituire null');
    $payload = analyticspro_gml_catalog_list_payload();
    counts_assert($payload['counts_available'] === false, 'counts_available deve essere false senza cache');
    counts_assert($payload['counts_generated'] === null, 'counts_generated deve essere null senza cache');
    $item = null;
    foreach ($payload['catalog'] as $row) {
        if ($row['belfiore'] === $code) {
            $item = $row;
        }
    }
    counts_assert($item !== null, 'Comune di test assente dal catalogo');
    counts_assert(
        $item !== null && $item['n_parcels'] === 0 && $item['n_fogli'] === 0 && $item['indexed'] === false,
        'Senza cache i conteggi devono essere 0/false (nessun calcolo al volo)'
    );

    // 2. Rigenerazione conteggi.
    $counts = analyticspro_gml_rebuild_counts_cache();
    counts_assert(is_file($countsPath), 'catalogo_counts.json non scritto');
    counts_assert($counts['count'] >= 1 && $counts['generated_at'] > 0, 'Payload rebuild incompleto');
    $entry = $counts['items'][$code] ?? [];
    counts_assert(($entry['n_parcels'] ?? null) === 42, 'n_parcels atteso 42');
    counts_assert(($entry['n_fogli'] ?? null) === 3, 'n_fogli atteso 3');
    counts_assert(($entry['indexed'] ?? null) === true, 'indexed atteso true');
    counts_assert(($entry['db_mtime'] ?? 0) === (int) filemtime($dbPath), 'db_mtime non coerente');

    // 3. Indici corrotti/modificati: il GET deve usare solo la cache.
    file_put_contents($dbPath, 'non un database sqlite');
    file_put_contents($fogliPath, json_encode(['000100' => '1']));
    clearstatcache();
    $payload = analyticspro_gml_catalog_list_payload();
    counts_assert($payload['counts_available'] === true, 'counts_available deve essere true con cache');
    counts_assert($payload['counts_generated'] === $counts['generated_at'], 'counts_generated non coerente');
    $item = null;
    foreach ($payload['catalog'] as $row) {
        if ($row['belfiore'] === $code) {
            $item = $row;
        }
    }
    counts_assert(
        $item !== null && $item['n_parcels'] === 42 && $item['n_fogli'] === 3 && $item['indexed'] === true,
        'Il GET deve leggere i conteggi dalla cache, senza aprire SQLite/_fogli.json'
    );

    // 3b. Aggiornamento incrementale: cambia SOLO la voce del comune indicato.
    $other = ['db_mtime' => 123, 'n_parcels' => 7, 'n_fogli' => 2, 'indexed' => true];
    file_put_contents($countsPath, json_encode([
        'generated_at' => 1,
        'count'        => 2,
        'items'        => ['Z999' => $other, $code => ['db_mtime' => 1, 'n_parcels' => 1, 'n_fogli' => 1, 'indexed' => false]],
    ]));
    @unlink($dbPath);
    $db = new SQLite3($dbPath);
    $db->exec('CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT)');
    $db->exec("INSERT INTO meta (key, value) VALUES ('count', '55'), ('complete', '1')");
    $db->close();
    file_put_contents($fogliPath, json_encode(['000100' => '1', '000200' => '2']));
    clearstatcache();
    analyticspro_gml_update_counts_cache_entry(strtolower($code));
    $updated = analyticspro_gml_load_counts_cache();
    counts_assert($updated !== null, 'Cache non leggibile dopo update incrementale');
    counts_assert(($updated['items']['Z999'] ?? null) === $other, 'Update incrementale ha alterato altre voci');
    counts_assert(($updated['count'] ?? 0) === 2, 'count atteso 2 dopo update incrementale');
    counts_assert(($updated['generated_at'] ?? 0) > 1, 'generated_at non aggiornato');
    $entry = $updated['items'][$code] ?? [];
    counts_assert(
        ($entry['n_parcels'] ?? null) === 55 && ($entry['n_fogli'] ?? null) === 2 && ($entry['indexed'] ?? null) === true
            && ($entry['db_mtime'] ?? 0) === (int) filemtime($dbPath),
        'Voce aggiornata incrementalmente non coerente'
    );

    // 3c. Cache assente: il file viene creato con la sola voce del comune.
    @unlink($countsPath);
    analyticspro_gml_update_counts_cache_entry($code);
    $created = analyticspro_gml_load_counts_cache();
    counts_assert($created !== null && array_keys($created['items']) === [$code], 'Cache non creata correttamente da update incrementale');

    // 3d. Cache corrotta: nessuna eccezione, file ricostruito.
    file_put_contents($countsPath, '{corrotto');
    analyticspro_gml_update_counts_cache_entry($code);
    $rebuilt = analyticspro_gml_load_counts_cache();
    counts_assert($rebuilt !== null && isset($rebuilt['items'][$code]), 'Cache corrotta non ricostruita');

    // 3e. Il worker aggiorna la cache conteggi dopo ogni comune indicizzato.
    $worker = (string) file_get_contents(dirname(__DIR__) . '/includes/gml_index_worker_logic.php');
    counts_assert(str_contains($worker, 'analyticspro_gml_update_counts_cache_entry($belfiore)'), 'Worker non aggiorna la cache conteggi');

    // 4. Il GET dell'endpoint non deve aprire SQLite né _fogli.json.
    $endpoint = (string) file_get_contents(dirname(__DIR__) . '/api/admin/gml_catalog.php');
    $getStart = strpos($endpoint, "if (\$method === 'GET')");
    $postStart = strpos($endpoint, "if (\$method === 'POST')");
    counts_assert($getStart !== false && $postStart !== false, 'Blocchi GET/POST non trovati');
    $getBlock = substr($endpoint, (int) $getStart, (int) $postStart - (int) $getStart);
    counts_assert(!str_contains($getBlock, 'SQLite3'), 'GET apre ancora SQLite3');
    counts_assert(!str_contains($getBlock, '_fogli.json'), 'GET legge ancora _fogli.json');
    counts_assert(!str_contains($getBlock, 'parcel_index_valid'), 'GET valida ancora gli indici SQLite');
    counts_assert(str_contains($getBlock, 'analyticspro_gml_catalog_list_payload'), 'GET non usa la cache conteggi');
    counts_assert(str_contains($endpoint, "'rebuild_counts'"), 'Azione rebuild_counts mancante');
} finally {
    @unlink($gmlFile);
    @unlink($dbPath);
    @unlink($fogliPath);
    if ($countsBackup !== null) {
        file_put_contents($countsPath, $countsBackup);
    } else {
        @unlink($countsPath);
    }
    if ($catalogBackup !== null) {
        file_put_contents($catalogPath, $catalogBackup);
    } else {
        @unlink($catalogPath);
    }
}

if ($errors === []) {
    echo "PASS: cache conteggi catalogo GML OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: {$error}\n";
}
exit(1);
