<?php
declare(strict_types=1);

/**
 * build_catasto_comune.php
 * On-demand import of cadastral parcels (particelle) per commune from AdE GeoJSON.
 *
 * Actions:
 *   GET ?action=check&codice_catastale=H501  → verify if commune is already imported
 *   GET ?action=auto&codice_catastale=H501   → auto-import if missing (no auth required)
 *   GET ?action=import&codice_catastale=H501 → force re-import (admin only)
 *   GET ?action=stats                        → database statistics
 *
 * The codice_catastale parameter accepts the Belfiore code (e.g. "H501" for Roma).
 */

require_once __DIR__ . '/catasto_admin_access.php';

date_default_timezone_set('Europe/Rome');

define('CC_DB_PATH',       __DIR__ . '/../cache/catasto/catasto_italia.db');
define('CC_DOWNLOAD_DIR',  __DIR__ . '/../cache/catasto/downloads');
define('CC_ADE_BASE_URL',  'https://wfs.cartografia.agenziaentrate.gov.it/inspire/wfs/GetDataset.php?dataset=');
define('CC_GEOJSON_SUFFIX', '_ple.geojson.gz');
define('CC_DOWNLOAD_TIMEOUT_SEC', 120);
define('CC_BUSY_TIMEOUT_MS', 10000);
define('CC_METERS_PER_DEGREE', 111000.0);
define('CC_CENTROID_EPSILON', 1e-12);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    handleRequest();
}

function handleRequest(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');

    $action = strtolower(trim((string)($_GET['action'] ?? 'stats')));

    try {
        switch ($action) {
            case 'check':
                $codice = requireCodiceCatastale();
                sendJson(checkComune($codice));
                break;

            case 'auto':
                $codice = requireCodiceCatastale();
                sendJson(autoImportComune($codice));
                break;

            case 'import':
                catastoEnforceAdminAccess(true);
                $codice = requireCodiceCatastale();
                sendJson(importComune($codice, true));
                break;

            case 'stats':
                sendJson(getDatabaseStats());
                break;

            default:
                sendError('Action non valida. Valori ammessi: check, auto, import, stats.', 400);
        }
    } catch (Throwable $e) {
        sendError($e->getMessage(), 500);
    }
}

function requireCodiceCatastale(): string
{
    $raw = strtoupper(trim((string)($_GET['codice_catastale'] ?? $_GET['codice_istat'] ?? '')));
    if ($raw === '') {
        sendError('Parametro codice_catastale obbligatorio', 400);
    }
    // Belfiore codes: letter + 3 digits (e.g. H501) or up to 6 alphanumeric chars
    if (!preg_match('/^[A-Z][0-9]{3}[A-Z0-9]{0,2}$/', $raw)) {
        sendError('Formato codice_catastale non valido (es: H501)', 400);
    }
    return $raw;
}

function checkComune(string $codice): array
{
    if (!file_exists(CC_DB_PATH)) {
        return ['ok' => true, 'imported' => false, 'codice_catastale' => $codice, 'particelle_count' => 0];
    }

    $db = openDatabase(true);
    try {
        $count = (int)$db->querySingle(
            'SELECT COUNT(*) FROM particelle WHERE cod_comune = ' . $db->escapeString($codice)
        );
        return [
            'ok'               => true,
            'imported'         => $count > 0,
            'codice_catastale' => $codice,
            'particelle_count' => $count,
        ];
    } finally {
        $db->close();
    }
}

function autoImportComune(string $codice): array
{
    $check = checkComune($codice);
    if ($check['imported']) {
        return array_merge($check, [
            'action'  => 'skipped',
            'message' => "Comune $codice già presente nel database ({$check['particelle_count']} particelle)",
        ]);
    }

    return importComune($codice, false);
}

function importComune(string $codice, bool $forceReimport): array
{
    $start = microtime(true);
    ensureCatastoDirectories();

    $db = openDatabase(false);

    // If force reimport, remove existing data first
    if ($forceReimport) {
        $db->exec('DELETE FROM particelle WHERE cod_comune = ' . $db->escapeString($codice));
    }

    $destGz   = CC_DOWNLOAD_DIR . "/{$codice}.geojson.gz";
    $destJson = CC_DOWNLOAD_DIR . "/{$codice}.geojson";

    try {
        $url = CC_ADE_BASE_URL . '/data/catasto_full/' . rawurlencode($codice) . CC_GEOJSON_SUFFIX;
        downloadFile($url, $destGz, $codice);
        decompressGzip($destGz, $destJson);

        $features  = parseGeoJson($destJson);
        $particelle = extractParticelle($features, $codice);
        $inserted  = insertParticelle($db, $particelle);

        $stats = getDatabaseStats($db);

        return [
            'ok'                  => true,
            'action'              => $forceReimport ? 'reimported' : 'imported',
            'codice_catastale'    => $codice,
            'features_parsed'     => count($features),
            'particelle_imported' => $inserted,
            'duration_sec'        => round(microtime(true) - $start, 2),
            'db_size_mb'          => $stats['db_size_mb'],
            'total_particelle'    => $stats['total_particelle'],
            'comuni_count'        => $stats['comuni_count'],
        ];
    } catch (Throwable $e) {
        logError("Import {$codice}: " . $e->getMessage());
        throw $e;
    } finally {
        $db->close();
        cleanupPath($destGz);
        cleanupPath($destJson);
    }
}

function getDatabaseStats(?SQLite3 $db = null): array
{
    $ownsConnection = false;
    if ($db === null && file_exists(CC_DB_PATH)) {
        $db = openDatabase(true);
        $ownsConnection = true;
    }

    try {
        $total   = $db ? (int)$db->querySingle('SELECT COUNT(*) FROM particelle') : 0;
        $comuni  = $db ? (int)$db->querySingle('SELECT COUNT(DISTINCT cod_comune) FROM particelle') : 0;
        $dbSize  = file_exists(CC_DB_PATH) ? filesize(CC_DB_PATH) : 0;

        return [
            'ok'               => true,
            'total_particelle' => $total,
            'comuni_count'     => $comuni,
            'db_size_mb'       => round($dbSize / 1048576, 2),
        ];
    } finally {
        if ($ownsConnection && $db instanceof SQLite3) {
            $db->close();
        }
    }
}

function openDatabase(bool $readonly): SQLite3
{
    ensureCatastoDirectories();
    $flags = $readonly ? SQLITE3_OPEN_READONLY : (SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
    $db = new SQLite3(CC_DB_PATH, $flags);
    $db->busyTimeout(CC_BUSY_TIMEOUT_MS);

    if (!$readonly) {
        $db->exec('PRAGMA journal_mode = WAL');
        $db->exec('PRAGMA synchronous = NORMAL');
        $db->exec('PRAGMA temp_store = MEMORY');
        $db->exec('PRAGMA foreign_keys = ON');
        ensureSchema($db);
    }

    return $db;
}

function ensureSchema(SQLite3 $db): void
{
    $db->exec('
        CREATE TABLE IF NOT EXISTS particelle (
            id          INTEGER PRIMARY KEY AUTOINCREMENT,
            cod_comune  TEXT    NOT NULL,
            comune      TEXT,
            provincia   TEXT,
            foglio      TEXT    NOT NULL,
            particella  TEXT    NOT NULL,
            subalterno  TEXT,
            lat         REAL    NOT NULL,
            lng         REAL    NOT NULL,
            polygon     TEXT,
            area_mq     REAL,
            created_at  TEXT    DEFAULT CURRENT_TIMESTAMP
        )
    ');
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_particella       ON particelle(cod_comune, foglio, particella)');
    $db->exec('CREATE INDEX        IF NOT EXISTS idx_comune_lookup    ON particelle(comune, provincia, foglio, particella)');
    $db->exec('CREATE INDEX        IF NOT EXISTS idx_cod_comune       ON particelle(cod_comune)');
    $db->exec('CREATE INDEX        IF NOT EXISTS idx_coords           ON particelle(lat, lng)');
}

function downloadFile(string $url, string $dest, string $label): void
{
    $handle = fopen($dest, 'wb');
    if ($handle === false) {
        throw new RuntimeException("Impossibile creare file di destinazione per $label");
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE           => $handle,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT        => CC_DOWNLOAD_TIMEOUT_SEC,
        CURLOPT_USERAGENT      => 'EasyCatasto-Analytics/1.0 (+https://github.com/simonedeitos/analytics)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FAILONERROR    => false,
        CURLOPT_HTTPHEADER     => ['Accept: application/octet-stream, */*;q=0.9'],
    ]);

    $result    = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode  = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    fclose($handle);

    if ($result === false || $curlError !== '' || $httpCode >= 400 || !file_exists($dest) || filesize($dest) === 0) {
        cleanupPath($dest);
        $reason = $curlError !== '' ? $curlError : "HTTP $httpCode";
        throw new RuntimeException("Download fallito per $label: $reason");
    }
}

function decompressGzip(string $gzPath, string $destPath): void
{
    $compressedData = file_get_contents($gzPath);
    if ($compressedData === false) {
        throw new RuntimeException("Impossibile leggere file compresso: $gzPath");
    }

    $data = gzdecode($compressedData);
    if ($data === false) {
        throw new RuntimeException("Impossibile decomprimere il file GeoJSON: $gzPath");
    }

    if (file_put_contents($destPath, $data) === false) {
        throw new RuntimeException("Impossibile scrivere il file GeoJSON decompresso: $destPath");
    }
}

function parseGeoJson(string $filePath): array
{
    $raw = file_get_contents($filePath);
    if ($raw === false) {
        throw new RuntimeException("Impossibile leggere il file GeoJSON: $filePath");
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException('File GeoJSON non valido: JSON non parsabile');
    }

    $type = $decoded['type'] ?? '';

    if ($type === 'FeatureCollection') {
        return (array)($decoded['features'] ?? []);
    }

    if ($type === 'Feature') {
        return [$decoded];
    }

    throw new RuntimeException("Tipo GeoJSON non supportato: $type");
}

function extractParticelle(array $features, string $defaultCodComune): array
{
    $particelle = [];

    foreach ($features as $feature) {
        if (!is_array($feature)) {
            continue;
        }

        $props  = (array)($feature['properties'] ?? []);
        $geom   = is_array($feature['geometry'] ?? null) ? $feature['geometry'] : null;

        if ($geom === null) {
            continue;
        }

        // Extract cadastral reference from properties
        // AdE GeoJSON uses 'nationalCadastralReference' or 'NCR' or similar
        $ncr = (string)(
            $props['nationalCadastralReference']
            ?? $props['NCR']
            ?? $props['NATCADREF']
            ?? $props['codice_particella']
            ?? ''
        );

        $codComune = $defaultCodComune;
        $foglio    = '';
        $particella = '';
        $subalterno = null;

        if ($ncr !== '') {
            $parts = array_map('trim', explode('.', $ncr));
            if (count($parts) >= 3) {
                $codComune  = $parts[0] !== '' ? strtoupper($parts[0]) : $defaultCodComune;
                $foglio     = normalizeNumericCode($parts[1]);
                $particella = normalizeNumericCode($parts[2]);
                $subalterno = isset($parts[3]) ? normalizeNumericCode($parts[3]) : null;
            }
        } else {
            // Fallback: try individual property fields
            $foglio     = normalizeNumericCode((string)($props['FOGLIO'] ?? $props['foglio'] ?? ''));
            $particella = normalizeNumericCode((string)($props['PARTICELLA'] ?? $props['particella'] ?? $props['NUM_PART'] ?? ''));
            $subalterno = isset($props['SUBALTERNO']) ? normalizeNumericCode((string)$props['SUBALTERNO']) : null;
        }

        if ($foglio === '' || $particella === '' || $particella === '0') {
            continue;
        }

        $centroid = calculateCentroidGeoJSON($geom);
        if ($centroid === null) {
            continue;
        }

        $area = calculateAreaGeoJSON($geom);

        $particelle[] = [
            'cod_comune' => $codComune,
            'comune'     => null,
            'provincia'  => null,
            'foglio'     => $foglio,
            'particella' => $particella,
            'subalterno' => $subalterno,
            'lat'        => $centroid['lat'],
            'lng'        => $centroid['lng'],
            'polygon'    => json_encode(extractRingCoords($geom), JSON_UNESCAPED_UNICODE),
            'area_mq'    => $area,
        ];
    }

    return $particelle;
}

function extractRingCoords(array $geom): array
{
    $type  = (string)($geom['type'] ?? '');
    $coords = $geom['coordinates'] ?? [];

    if ($type === 'Polygon' && is_array($coords) && !empty($coords[0])) {
        return array_map('normalizeGeoJsonCoord', (array)$coords[0]);
    }

    if ($type === 'MultiPolygon' && is_array($coords) && !empty($coords[0][0])) {
        return array_map('normalizeGeoJsonCoord', (array)$coords[0][0]);
    }

    return [];
}

function normalizeGeoJsonCoord(mixed $c): array
{
    if (is_array($c) && count($c) >= 2) {
        // GeoJSON coordinates are [longitude, latitude]
        return ['lat' => (float)$c[1], 'lng' => (float)$c[0]];
    }
    return ['lat' => 0.0, 'lng' => 0.0];
}

function calculateCentroidGeoJSON(array $geom): ?array
{
    $ring = extractRingCoords($geom);
    if (count($ring) < 3) {
        return null;
    }

    // Polygon centroid via shoelace
    $area2  = 0.0;
    $sumLat = 0.0;
    $sumLng = 0.0;
    $n      = count($ring);

    for ($i = 0; $i < $n; $i++) {
        $j      = ($i + 1) % $n;
        $cross  = ($ring[$i]['lng'] * $ring[$j]['lat']) - ($ring[$j]['lng'] * $ring[$i]['lat']);
        $area2 += $cross;
        $sumLng += ($ring[$i]['lng'] + $ring[$j]['lng']) * $cross;
        $sumLat += ($ring[$i]['lat'] + $ring[$j]['lat']) * $cross;
    }

    if (abs($area2) < CC_CENTROID_EPSILON) {
        // Degenerate polygon: use arithmetic mean
        $lat = 0.0;
        $lng = 0.0;
        foreach ($ring as $p) {
            $lat += $p['lat'];
            $lng += $p['lng'];
        }
        return ['lat' => $lat / $n, 'lng' => $lng / $n];
    }

    $factor = 1.0 / (3.0 * $area2);
    return [
        'lat' => $sumLat * $factor,
        'lng' => $sumLng * $factor,
    ];
}

function calculateAreaGeoJSON(array $geom): float
{
    $ring = extractRingCoords($geom);
    if (count($ring) < 3) {
        return 0.0;
    }

    // Local planar approximation (Shoelace formula converted to m²)
    $latRef = 0.0;
    foreach ($ring as $p) {
        $latRef += $p['lat'];
    }
    $latRef /= count($ring);
    $metersPerDegreeLng = CC_METERS_PER_DEGREE * cos(deg2rad($latRef));

    $area = 0.0;
    $n    = count($ring);
    for ($i = 0; $i < $n; $i++) {
        $j   = ($i + 1) % $n;
        $x1  = $ring[$i]['lng'] * $metersPerDegreeLng;
        $y1  = $ring[$i]['lat'] * CC_METERS_PER_DEGREE;
        $x2  = $ring[$j]['lng'] * $metersPerDegreeLng;
        $y2  = $ring[$j]['lat'] * CC_METERS_PER_DEGREE;
        $area += ($x1 * $y2) - ($x2 * $y1);
    }

    return abs($area) / 2.0;
}

function normalizeNumericCode(string $value): string
{
    $numericPart = preg_replace('/\D+/', '', trim($value));
    if ($numericPart === '') {
        return '';
    }
    $stripped = ltrim($numericPart, '0');
    return $stripped === '' ? '0' : $stripped;
}

function insertParticelle(SQLite3 $db, array $particelle): int
{
    if (!$particelle) {
        return 0;
    }

    $db->exec('BEGIN TRANSACTION');

    $stmt = $db->prepare('
        INSERT OR IGNORE INTO particelle
            (cod_comune, comune, provincia, foglio, particella, subalterno, lat, lng, polygon, area_mq)
        VALUES
            (:cod_comune, :comune, :provincia, :foglio, :particella, :subalterno, :lat, :lng, :polygon, :area_mq)
    ');

    if ($stmt === false) {
        $db->exec('ROLLBACK');
        throw new RuntimeException('Impossibile preparare la query di inserimento');
    }

    $inserted = 0;

    try {
        foreach ($particelle as $p) {
            $stmt->reset();
            $stmt->clear();
            $stmt->bindValue(':cod_comune',  $p['cod_comune'],  SQLITE3_TEXT);
            $stmt->bindValue(':comune',      $p['comune'],      $p['comune']      === null ? SQLITE3_NULL : SQLITE3_TEXT);
            $stmt->bindValue(':provincia',   $p['provincia'],   $p['provincia']   === null ? SQLITE3_NULL : SQLITE3_TEXT);
            $stmt->bindValue(':foglio',      $p['foglio'],      SQLITE3_TEXT);
            $stmt->bindValue(':particella',  $p['particella'],  SQLITE3_TEXT);
            $stmt->bindValue(':subalterno',  $p['subalterno'],  $p['subalterno']  === null ? SQLITE3_NULL : SQLITE3_TEXT);
            $stmt->bindValue(':lat',         $p['lat'],         SQLITE3_FLOAT);
            $stmt->bindValue(':lng',         $p['lng'],         SQLITE3_FLOAT);
            $stmt->bindValue(':polygon',     $p['polygon'],     $p['polygon']     === null ? SQLITE3_NULL : SQLITE3_TEXT);
            $stmt->bindValue(':area_mq',     $p['area_mq'],     SQLITE3_FLOAT);

            $result = $stmt->execute();
            if ($result instanceof SQLite3Result) {
                $result->finalize();
            }
            if ($db->changes() > 0) {
                $inserted++;
            }
        }

        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    } finally {
        $stmt->close();
    }

    return $inserted;
}

function ensureCatastoDirectories(): void
{
    $catastoDir = dirname(CC_DB_PATH);
    if (!is_dir($catastoDir) && !mkdir($catastoDir, 0755, true) && !is_dir($catastoDir)) {
        throw new RuntimeException('Impossibile creare la directory cache/catasto');
    }

    if (!is_dir(CC_DOWNLOAD_DIR) && !mkdir(CC_DOWNLOAD_DIR, 0755, true) && !is_dir(CC_DOWNLOAD_DIR)) {
        throw new RuntimeException('Impossibile creare la directory download catasto');
    }

    $gitkeep = $catastoDir . '/.gitkeep';
    if (!file_exists($gitkeep)) {
        file_put_contents($gitkeep, "# Database Catasto Italia\n# Generato da api/build_catasto_comune.php\n");
    }
}

function cleanupPath(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
}

function logError(string $message): void
{
    $logDir  = dirname(CC_DB_PATH);
    $logFile = $logDir . '/errors.log';
    $line    = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

function sendJson(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function sendError(string $message, int $status = 500): void
{
    http_response_code($status);
    sendJson(['ok' => false, 'error' => $message]);
}
