<?php
declare(strict_types=1);

/**
 * api/download_foglio_wfs.php
 * Scarica TUTTE le particelle di un foglio catastale via WFS (Agenzia delle Entrate)
 * e le salva nel database SQLite condiviso con get_coords_wfs.php.
 * Lookup successivi da get_coords_wfs.php saranno istantanei (dalla cache).
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

date_default_timezone_set('Europe/Rome');
set_time_limit(60);

$codCatastale = strtoupper(trim((string)($_GET['cod_catastale'] ?? '')));
$foglio = trim((string)($_GET['foglio'] ?? ''));

if ($codCatastale === '' || $foglio === '') {
    sendJson(['ok' => false, 'error' => 'Parametri cod_catastale e foglio obbligatori'], 400);
}

// Normalizza foglio (rimuovi zeri iniziali come in get_coords_wfs.php)
$foglioNorm = ltrim($foglio, '0');
if ($foglioNorm === '') {
    $foglioNorm = '0';
}

// Valida codice catastale (lettera + 3 cifre)
if (!preg_match('/^[A-Z]\d{3}$/', $codCatastale)) {
    sendJson(['ok' => false, 'error' => 'Codice catastale non valido'], 400);
}

try {
    $db = openCacheDB();
} catch (Throwable $e) {
    error_log('[DownloadFoglio] Errore apertura DB: ' . $e->getMessage());
    sendJson(['ok' => false, 'error' => 'Errore apertura cache catasto'], 500);
}

try {
    // 1. Verifica se il foglio è già in cache
    $cachedCount = countCachedFoglio($db, $codCatastale, $foglioNorm);
    if ($cachedCount > 0) {
        sendJson([
            'ok' => true,
            'source' => 'cache',
            'particelle_count' => $cachedCount,
            'cod_catastale' => $codCatastale,
            'foglio' => $foglioNorm,
        ]);
    }

    // 2. Download da WFS
    $particelle = downloadFoglioFromWFS($codCatastale, $foglioNorm);
    if ($particelle === null || count($particelle) === 0) {
        sendJson(['ok' => false, 'error' => 'Foglio non trovato nel catasto WFS'], 404);
    }

    // 3. Salva batch nel DB (stesso DB di get_coords_wfs.php)
    saveFoglioBatch($db, $codCatastale, $foglioNorm, $particelle);

    sendJson([
        'ok' => true,
        'source' => 'WFS-AdE',
        'particelle_count' => count($particelle),
        'cod_catastale' => $codCatastale,
        'foglio' => $foglioNorm,
    ]);
} catch (Throwable $e) {
    error_log('[DownloadFoglio] Exception: ' . $e->getMessage());
    sendJson(['ok' => false, 'error' => 'Errore server'], 500);
} finally {
    $db->close();
}

/**
 * Scarica tutte le particelle di un foglio via WFS usando un filtro PropertyIsLike.
 * Il formato localId è: CODICE.FOGLIO.PARTICELLA (es. B394.34.351)
 */
function downloadFoglioFromWFS(string $codCatastale, string $foglio): ?array
{
    // Pattern per il foglio: es. "B394.34.*" (tutte le particelle del foglio)
    $pattern = htmlspecialchars($codCatastale, ENT_XML1 | ENT_COMPAT, 'UTF-8')
        . '.'
        . htmlspecialchars($foglio, ENT_XML1 | ENT_COMPAT, 'UTF-8')
        . '.*';

    $filterXml = '<ogc:Filter xmlns:ogc="http://www.opengis.net/ogc">'
        . '<ogc:PropertyIsLike wildCard="*" singleChar="?" escapeChar="!">'
        . '<ogc:PropertyName>localId</ogc:PropertyName>'
        . '<ogc:Literal>' . $pattern . '</ogc:Literal>'
        . '</ogc:PropertyIsLike>'
        . '</ogc:Filter>';

    $query = http_build_query([
        'SERVICE'      => 'WFS',
        'VERSION'      => '2.0.0',
        'REQUEST'      => 'GetFeature',
        'TYPENAME'     => 'cadastralparcel',
        'FILTER'       => $filterXml,
        'OUTPUTFORMAT' => 'application/json',
        'COUNT'        => '5000',
    ]);

    $url = 'https://wfs.cartografia.agenziaentrate.gov.it/inspire/wfs/ows01.php?' . $query;

    $response = httpGet($url);
    if ($response === null) {
        error_log("[DownloadFoglio] Query WFS fallita per {$codCatastale} foglio {$foglio}");
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || !isset($data['features']) || !is_array($data['features'])) {
        return null;
    }

    $particelle = [];
    foreach ($data['features'] as $feature) {
        if (!is_array($feature)) {
            continue;
        }

        $geom = $feature['geometry'] ?? null;
        if (!is_array($geom)) {
            continue;
        }

        $centroid = calculateCentroidFromGeoJSON($geom);
        if ($centroid === null) {
            continue;
        }

        // Estrai numero particella da localId (formato: CODICE.FOGLIO.PARTICELLA)
        $localId = (string)($feature['properties']['localId'] ?? '');
        $parts = explode('.', $localId);
        if (count($parts) < 3) {
            continue;
        }

        // La particella può avere zeri iniziali nel localId; normalizziamo
        $particellaRaw = $parts[2];
        $particellaDigits = preg_replace('/\D+/', '', $particellaRaw) ?? '';
        if ($particellaDigits !== '') {
            $particellaNum = ltrim($particellaDigits, '0');
            if ($particellaNum === '') {
                $particellaNum = '0';
            }
        } else {
            $particellaNum = strtoupper($particellaRaw);
        }

        if ($particellaNum === '') {
            continue;
        }

        $particelle[] = [
            'particella' => $particellaNum,
            'lat'        => $centroid['lat'],
            'lng'        => $centroid['lng'],
            'area_mq'    => calculateAreaFromGeoJSON($geom),
        ];
    }

    return $particelle;
}

function countCachedFoglio(SQLite3 $db, string $codCatastale, string $foglio): int
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS cnt FROM particelle_cache WHERE cod_catastale = :cod AND foglio = :foglio'
    );
    if (!$stmt) {
        return 0;
    }

    $stmt->bindValue(':cod', $codCatastale, SQLITE3_TEXT);
    $stmt->bindValue(':foglio', $foglio, SQLITE3_TEXT);

    $result = $stmt->execute();
    if (!$result) {
        return 0;
    }

    $row = $result->fetchArray(SQLITE3_ASSOC);
    return $row ? (int)($row['cnt'] ?? 0) : 0;
}

function saveFoglioBatch(SQLite3 $db, string $codCatastale, string $foglio, array $particelle): void
{
    $db->exec('BEGIN TRANSACTION');

    $stmt = $db->prepare(
        'INSERT OR REPLACE INTO particelle_cache (cod_catastale, foglio, particella, lat, lng, area_mq)
         VALUES (:cod, :foglio, :part, :lat, :lng, :area)'
    );

    if (!$stmt) {
        $db->exec('ROLLBACK');
        return;
    }

    foreach ($particelle as $p) {
        $stmt->bindValue(':cod',    $codCatastale, SQLITE3_TEXT);
        $stmt->bindValue(':foglio', $foglio,       SQLITE3_TEXT);
        $stmt->bindValue(':part',   $p['particella'], SQLITE3_TEXT);
        $stmt->bindValue(':lat',    (float)$p['lat'], SQLITE3_FLOAT);
        $stmt->bindValue(':lng',    (float)$p['lng'], SQLITE3_FLOAT);
        if ($p['area_mq'] !== null) {
            $stmt->bindValue(':area', (float)$p['area_mq'], SQLITE3_FLOAT);
        } else {
            $stmt->bindValue(':area', null, SQLITE3_NULL);
        }
        $stmt->execute();
        $stmt->reset();
    }

    $db->exec('COMMIT');
}

function openCacheDB(): SQLite3
{
    $dbPath = __DIR__ . '/../cache/catasto/catasto_cache.db';
    $dbDir  = dirname($dbPath);

    if (!is_dir($dbDir) && !mkdir($dbDir, 0755, true) && !is_dir($dbDir)) {
        throw new RuntimeException('Impossibile creare directory cache');
    }

    $db = new SQLite3($dbPath);
    $db->busyTimeout(15000);

    $db->exec('CREATE TABLE IF NOT EXISTS particelle_cache (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cod_catastale TEXT NOT NULL,
        foglio TEXT NOT NULL,
        particella TEXT NOT NULL,
        lat REAL NOT NULL,
        lng REAL NOT NULL,
        area_mq REAL,
        cached_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(cod_catastale, foglio, particella)
    )');

    $db->exec(
        'CREATE INDEX IF NOT EXISTS idx_particelle_cache_lookup ON particelle_cache(cod_catastale, foglio, particella)'
    );
    $db->exec(
        'CREATE INDEX IF NOT EXISTS idx_particelle_cache_foglio ON particelle_cache(cod_catastale, foglio)'
    );

    return $db;
}

function isValidGeoPoint(mixed $point): bool
{
    return is_array($point)
        && isset($point[0], $point[1])
        && is_numeric($point[0])
        && is_numeric($point[1]);
}

function calculateCentroidFromGeoJSON(array $geom): ?array
{
    $coords = $geom['coordinates'] ?? null;
    if (!is_array($coords)) {
        return null;
    }

    $ring = [];
    $type = $geom['type'] ?? '';
    if ($type === 'Polygon') {
        $ring = $coords[0] ?? [];
    } elseif ($type === 'MultiPolygon') {
        $ring = $coords[0][0] ?? [];
    }

    if (!is_array($ring) || count($ring) < 3) {
        return null;
    }

    $latSum = 0.0;
    $lngSum = 0.0;
    $count  = 0;

    foreach ($ring as $point) {
        if (!isValidGeoPoint($point)) {
            continue;
        }
        $lngSum += (float)$point[0];
        $latSum += (float)$point[1];
        $count++;
    }

    if ($count === 0) {
        return null;
    }

    return [
        'lat' => $latSum / $count,
        'lng' => $lngSum / $count,
    ];
}

function calculateAreaFromGeoJSON(array $geom): ?float
{
    if (($geom['type'] ?? '') !== 'Polygon') {
        return null;
    }

    $ring = $geom['coordinates'][0] ?? null;
    if (!is_array($ring) || count($ring) < 3) {
        return null;
    }

    $area  = 0.0;
    $count = count($ring);

    for ($i = 0; $i < $count - 1; $i++) {
        $current = $ring[$i] ?? null;
        $next    = $ring[$i + 1] ?? null;
        if (!isValidGeoPoint($current) || !isValidGeoPoint($next)) {
            continue;
        }
        $area += ((float)$current[0] * (float)$next[1]) - ((float)$next[0] * (float)$current[1]);
    }

    $area = abs($area) / 2.0;
    $metersPerDegree = 111000.0;
    return $area * ($metersPerDegree * $metersPerDegree);
}

function httpGet(string $url): ?string
{
    if (!function_exists('curl_init')) {
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'EasyCatasto-Analytics/2.0 (+https://github.com/simonedeitos/analytics)',
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $err !== '' || $code >= 400) {
        return null;
    }

    return (string)$body;
}

function sendJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
