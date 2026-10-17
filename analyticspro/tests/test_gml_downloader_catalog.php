<?php

declare(strict_types=1);

// Eseguire con: php analyticspro/tests/test_gml_downloader_catalog.php
$root = sys_get_temp_dir() . '/analyticspro_gml_downloader_' . getmypid();
define('ANALYTICSPRO_ROOT', $root);
mkdir($root, 0775, true);
register_shutdown_function(static function () use ($root): void {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $path) {
        $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
    }
    rmdir($root);
});

require_once dirname(__DIR__) . '/includes/gml_catalog.php';
analyticspro_gml_ensure_dirs();
$logPath = $root . '/php-error.log';
ini_set('error_log', $logPath);
set_error_handler(static function (int $severity, string $message): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new RuntimeException($message, $severity);
});

function downloader_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function downloader_index(string $code, array $meta): string
{
    $path = analyticspro_gml_index_dir() . '/' . $code . '.sqlite';
    $db = new SQLite3($path);
    $db->exec('CREATE TABLE parcels (
        cod_foglio TEXT, particella TEXT, particella_norm TEXT,
        lat REAL, lon REAL, area_mq REAL,
        PRIMARY KEY (cod_foglio, particella)
    )');
    $db->exec("INSERT INTO parcels VALUES ('003300', '147', '147', 45.5, 10.5, 1200.0)");
    $db->exec('CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT)');
    $stmt = $db->prepare('INSERT INTO meta VALUES (:key, :value)');
    foreach ($meta as $key => $value) {
        $stmt->bindValue(':key', $key, SQLITE3_TEXT);
        $stmt->bindValue(':value', $value, SQLITE3_TEXT);
        $stmt->execute();
        $stmt->reset();
    }
    $stmt->close();
    $db->close();
    return $path;
}

$validMeta = ['complete' => '1', 'schema_version' => '1', 'source_signature' => hash('sha256', 'local:123:456')];
foreach ([
    'B394' => $validMeta,
    'C123' => array_diff_key($validMeta, ['schema_version' => true]),
    'C124' => array_replace($validMeta, ['schema_version' => '2']),
    'C125' => array_diff_key($validMeta, ['source_signature' => true]),
    'C126' => array_replace($validMeta, ['source_signature' => '']),
    'C127' => array_replace($validMeta, ['complete' => '0']),
] as $code => $meta) {
    downloader_index($code, $meta);
}

$manifest = [
    '_meta' => [
        'generator' => 'GmlDownloader',
        'generated_at' => '2026-10-17T12:00:00Z',
        'file_count' => 100,
        'dir_mtime' => 1234567890,
    ],
    'catalog' => [],
];
foreach (['B394', 'C123', 'C124', 'C125', 'C126', 'C127', 'C128'] as $code) {
    $manifest['catalog'][$code] = [
        'ple' => null, 'map' => null, 'nome' => 'Comune ' . $code,
        'size_ple' => 0, 'size_map' => 0, 'mtime' => 1234567890,
        'indexed' => $code !== 'B394', 'schema_version' => 1,
    ];
}
$manifest['catalog']['C129'] = ['nome' => 'Senza percorsi', 'indexed' => true];
$manifest['catalog']['../invalid'] = ['indexed' => true];
$manifest['catalog']['C130'] = 'invalid';
$cachePath = analyticspro_gml_index_dir() . '/catalogo.json';
$writeManifest = static function () use (&$manifest, $cachePath): string {
    $raw = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    file_put_contents($cachePath, $raw);
    clearstatcache();
    return $raw;
};
$original = $writeManifest();
$reversePath = analyticspro_gml_reverse_index_path();
file_put_contents($reversePath, '{"version":1,"exact":{},"compact":{}}');
$catalog = analyticspro_gml_build_catalog();
downloader_assert(is_file($reversePath), 'Conservare l’indice inverso caricato quando non ci sono GML locali.');
downloader_assert(isset($catalog['B394']), 'Il catalogo esterno non deve essere ricostruito dal filesystem vuoto.');
downloader_assert($catalog['B394']['indexed'] === true, 'Il flag false del manifest deve essere ri-validato.');
downloader_assert(!isset($catalog['../invalid']) && !isset($catalog['C130']), 'Ignorare voci del manifest non valide.');
downloader_assert(analyticspro_gml_parcel_index_valid('b394'), 'Indice senza GML valido tramite meta.');
$result = analyticspro_gml_lookup('B394', '33', '0147');
downloader_assert($result !== null && $result['lat'] === 45.5 && $result['lon'] === 10.5, 'Coordinate lette da SQLite.');
downloader_assert($result['area_mq'] === 1200.0 && $result['cod_foglio'] === '003300', 'Output lookup invariato.');
downloader_assert(analyticspro_gml_lookup('B394', '33', '999') === null, 'Particella assente: nessun fallback.');
foreach (['C123', 'C124', 'C125', 'C126', 'C127', 'C128', 'C129'] as $code) {
    downloader_assert($catalog[$code]['indexed'] === false, $code . ': non fidarsi del flag indexed esterno.');
    downloader_assert(!analyticspro_gml_parcel_index_valid($code), $code . ': indice non valido.');
    downloader_assert(analyticspro_gml_lookup($code, '33', '147') === null, $code . ': nessun GML per fallback.');
}
$log = file_get_contents($logPath);
downloader_assert(str_contains($log, 'schema_version') && str_contains($log, 'C123') && str_contains($log, 'C124'), 'Warning schema assente/diverso.');
downloader_assert(str_contains($log, 'source_signature') && str_contains($log, 'GmlDownloader'), 'Warning firma assente/vuota.');
downloader_assert(str_contains($log, 'nessun file GML fisico disponibile'), 'Messaggio operativo per comuni senza GML.');
downloader_assert(!str_contains($log, 'pagina Admin'), 'Non suggerire il worker PHP per comuni senza GML.');
analyticspro_gml_build_catalog(true);
downloader_assert(file_get_contents($cachePath) === $original, 'Anche force deve preservare manifest e metadati.');

// Aggiornamenti FTP nello stesso secondo, anche senza cambiare generated_at.
$mtime = filemtime($cachePath);
$manifest['catalog']['B394']['nome'] = 'Nome aggiornato';
$writeManifest();
touch($cachePath, $mtime);
clearstatcache();
downloader_assert(analyticspro_gml_build_catalog()['B394']['nome'] === 'Nome aggiornato', 'Rilevare contenuto modificato a parità di mtime.');
$manifest['_meta']['generated_at'] = '2026-10-17T13:00:00Z';
$manifest['catalog']['C131'] = $manifest['catalog']['C128'];
$writeManifest();
downloader_assert(isset(analyticspro_gml_build_catalog()['C131']), 'Rilevare nuova versione del manifest.');
$db = new SQLite3(analyticspro_gml_index_dir() . '/B394.sqlite');
$db->exec("UPDATE meta SET value = '0' WHERE key = 'complete'");
$db->close();
downloader_assert(!analyticspro_gml_parcel_index_valid('B394'), 'Il lookup deve ri-validare SQLite anche con catalogo in memoria.');
downloader_assert(analyticspro_gml_build_catalog(true)['B394']['indexed'] === false, 'Force deve ri-validare tutti gli indici.');

// Migrazione mista: il filesystem prevale, il manifest non viene sovrascritto.
$legacyPath = analyticspro_gml_dir() . '/B394_CALCINATO_ple.gml';
copy(__DIR__ . '/fixtures/B394_test_ple.gml', $legacyPath);
$db = new SQLite3(analyticspro_gml_index_dir() . '/B394.sqlite');
$db->exec("DELETE FROM meta WHERE key IN ('schema_version', 'source_signature')");
$db->exec("UPDATE meta SET value = '1' WHERE key = 'complete'");
$db->close();
touch($legacyPath, time() - 100);
touch(analyticspro_gml_index_dir() . '/B394.sqlite', time());
copy(__DIR__ . '/fixtures/B394_test_ple.gml', analyticspro_gml_dir() . '/A001_LOCALE_ple.gml');
file_put_contents($reversePath, '{"version":1,"exact":{},"compact":{}}');
clearstatcache();
$original = file_get_contents($cachePath);
$catalog = analyticspro_gml_build_catalog();
downloader_assert(!is_file($reversePath), 'Nel caso misto rigenerare l’indice inverso dal catalogo unito.');
downloader_assert($catalog['B394']['ple'] === $legacyPath && $catalog['B394']['nome'] === 'Calcinato', 'La scansione locale prevale sul manifest.');
downloader_assert(isset($catalog['A001'], $catalog['C123']), 'Merge di comuni locali ed esterni.');
downloader_assert(analyticspro_gml_parcel_index_valid('B394'), 'Gli indici PHP storici non richiedono nuovi meta.');
downloader_assert(analyticspro_gml_lookup('B394', '33', '147')['lat'] === 45.5, 'Lookup storico via SQLite invariato.');
analyticspro_gml_build_catalog(true);
downloader_assert(file_get_contents($cachePath) === $original, 'Il caso misto non deve distruggere la fonte esterna.');
analyticspro_gml_invalidate_catalog_cache();
downloader_assert(file_get_contents($cachePath) === $original, 'Upload e invalidazione admin devono preservare il manifest autorevole.');
downloader_assert(isset(analyticspro_gml_build_catalog(true)['C123']), 'Comuni esterni preservati dopo invalidazione admin.');
touch(analyticspro_gml_index_dir() . '/B394.sqlite', time() - 200);
clearstatcache();
downloader_assert(!analyticspro_gml_parcel_index_valid('B394'), 'Indice storico più vecchio del GML non valido.');
$streamed = analyticspro_gml_lookup('B394', '33', '148');
downloader_assert($streamed !== null && $streamed['lat'] > 45.52 && $streamed['lat'] < 45.56, 'Fallback streaming storico ancora funzionante.');
downloader_assert(str_contains(file_get_contents($logPath), 'pagina Admin'), 'Warning storico invariato.');
unlink($legacyPath);
clearstatcache();
downloader_assert(analyticspro_gml_build_catalog()['B394']['ple'] === null, 'Rimosso GML locale: ripristinare la voce esterna.');
downloader_assert(!analyticspro_gml_parcel_index_valid('B394'), 'Senza GML i meta storici non bastano.');

// Senza il marcatore del produttore, resta la cache/scansione storica.
$manifest['_meta']['generator'] = 'Other';
$writeManifest();
$catalog = analyticspro_gml_build_catalog(true);
downloader_assert(array_keys($catalog) === ['A001'], 'Non importare manifest di produttori sconosciuti.');
downloader_assert(!isset(json_decode(file_get_contents($cachePath), true)['_meta']['generator']), 'Cache filesystem storica invariata.');
analyticspro_gml_invalidate_catalog_cache();
downloader_assert(!is_file($cachePath), 'Invalidazione storica elimina ancora la cache filesystem.');
echo "PASS: catalogo GmlDownloader, meta SQLite, aggiornamenti FTP, merge e fallback storico\n";
