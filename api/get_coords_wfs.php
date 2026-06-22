<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

date_default_timezone_set('Europe/Rome');

$comune = normalizeComune((string)($_GET['comune'] ?? ''));
$provincia = normalizeProvincia((string)($_GET['provincia'] ?? ''));
$foglio = normalizeCadastralToken((string)($_GET['foglio'] ?? ''));
$particella = normalizeCadastralToken((string)($_GET['particella'] ?? ''));

if ($comune === '' || $provincia === '' || $foglio === '' || $particella === '') {
    sendJson(['ok' => false, 'error' => 'Parametri mancanti'], 400);
}

$codCatastale = lookupCodiceCatastale($comune, $provincia);
if ($codCatastale === null) {
    sendJson(['ok' => false, 'error' => "Comune non trovato: {$comune} ({$provincia})"], 404);
}

try {
    $db = openCacheDB();
} catch (Throwable $e) {
    sendJson(['ok' => false, 'error' => 'Errore apertura cache catasto'], 500);
}

try {
    $cached = getCachedParticella($db, $codCatastale, $foglio, $particella);
    if ($cached !== null) {
        sendJson($cached);
    }

    $wfsData = queryWFS($codCatastale, $foglio, $particella);
    if ($wfsData === null) {
        sendJson(['ok' => false, 'error' => 'Particella non trovata nel catasto'], 404);
    }

    saveCachedParticella($db, $codCatastale, $foglio, $particella, $wfsData);
    sendJson($wfsData);
} finally {
    $db->close();
}

function lookupCodiceCatastale(string $comune, string $provincia): ?string
{
    static $comuniMap = null;

    if ($comuniMap === null) {
        $jsonPath = __DIR__ . '/../data/comuni_catastali.json';
        if (!is_file($jsonPath)) {
            return null;
        }

        $raw = file_get_contents($jsonPath);
        if ($raw === false) {
            return null;
        }

        $comuni = json_decode($raw, true);
        if (!is_array($comuni)) {
            return null;
        }

        $comuniMap = [];
        foreach ($comuni as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $nome = normalizeComune((string)($entry['nome'] ?? ''));
            $sigla = normalizeProvincia((string)($entry['sigla_provincia'] ?? ''));
            $codice = strtoupper(trim((string)($entry['codice_catastale'] ?? '')));
            if ($nome === '' || $sigla === '' || $codice === '') {
                continue;
            }
            $comuniMap["{$nome}|{$sigla}"] = $codice;
        }
    }

    return $comuniMap["{$comune}|{$provincia}"] ?? null;
}

function queryWFS(string $codCatastale, string $foglio, string $particella): ?array
{
    $localId = sprintf('%s.%s.%s', $codCatastale, $foglio, $particella);
    $filterXml = '<ogc:Filter xmlns:ogc="http://www.opengis.net/ogc"><ogc:PropertyIsEqualTo><ogc:PropertyName>localId</ogc:PropertyName><ogc:Literal>'
        . htmlspecialchars($localId, ENT_XML1 | ENT_COMPAT, 'UTF-8')
        . '</ogc:Literal></ogc:PropertyIsEqualTo></ogc:Filter>';

    $query = http_build_query([
        'SERVICE' => 'WFS',
        'VERSION' => '2.0.0',
        'REQUEST' => 'GetFeature',
        'TYPENAME' => 'cadastralparcel',
        'FILTER' => $filterXml,
        'OUTPUTFORMAT' => 'application/json',
    ]);

    $url = 'https://wfs.cartografia.agenziaentrate.gov.it/inspire/wfs/ows01.php?' . $query;

    $response = httpGet($url);
    if ($response === null) {
        error_log("[WFS] Query failed for {$localId}");
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        return null;
    }

    $features = $data['features'] ?? null;
    if (!is_array($features) || $features === []) {
        return null;
    }

    $feature = $features[0];
    if (!is_array($feature)) {
        return null;
    }

    $geom = $feature['geometry'] ?? null;
    if (!is_array($geom)) {
        return null;
    }

    $centroid = calculateCentroidFromGeoJSON($geom);
    if ($centroid === null) {
        return null;
    }

    return [
        'ok' => true,
        'lat' => $centroid['lat'],
        'lng' => $centroid['lng'],
        'area_mq' => calculateAreaFromGeoJSON($geom),
        'source' => 'WFS-AdE',
        'cod_catastale' => $codCatastale,
        'foglio' => $foglio,
        'particella' => $particella,
    ];
}

function calculateCentroidFromGeoJSON(array $geom): ?array
{
    $coords = $geom['coordinates'] ?? null;
    if (!is_array($coords)) {
        return null;
    }

    $ring = [];
    if (($geom['type'] ?? '') === 'Polygon') {
        $ring = $coords[0] ?? [];
    } elseif (($geom['type'] ?? '') === 'MultiPolygon') {
        $ring = $coords[0][0] ?? [];
    }

    if (!is_array($ring) || count($ring) < 3) {
        return null;
    }

    $latSum = 0.0;
    $lngSum = 0.0;
    $count = 0;

    foreach ($ring as $point) {
        if (!is_array($point) || !isset($point[0], $point[1]) || !is_numeric($point[0]) || !is_numeric($point[1])) {
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

    $area = 0.0;
    $count = count($ring);

    for ($i = 0; $i < $count - 1; $i++) {
        $current = $ring[$i] ?? null;
        $next = $ring[$i + 1] ?? null;
        if (!is_array($current) || !is_array($next) || !isset($current[0], $current[1], $next[0], $next[1])) {
            continue;
        }

        $x1 = (float)$current[0];
        $y1 = (float)$current[1];
        $x2 = (float)$next[0];
        $y2 = (float)$next[1];

        $area += ($x1 * $y2) - ($x2 * $y1);
    }

    $area = abs($area) / 2.0;

    $metersPerDegree = 111000.0;
    return $area * ($metersPerDegree * $metersPerDegree);
}

function openCacheDB(): SQLite3
{
    $dbPath = __DIR__ . '/../cache/catasto/catasto_cache.db';
    $dbDir = dirname($dbPath);

    if (!is_dir($dbDir) && !mkdir($dbDir, 0755, true) && !is_dir($dbDir)) {
        throw new RuntimeException('Impossibile creare directory cache');
    }

    $db = new SQLite3($dbPath);
    $db->busyTimeout(5000);

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

    $db->exec('CREATE INDEX IF NOT EXISTS idx_particelle_cache_lookup ON particelle_cache(cod_catastale, foglio, particella)');

    return $db;
}

function getCachedParticella(SQLite3 $db, string $codCatastale, string $foglio, string $particella): ?array
{
    $stmt = $db->prepare('SELECT lat, lng, area_mq FROM particelle_cache WHERE cod_catastale = :cod AND foglio = :foglio AND particella = :part LIMIT 1');
    if (!$stmt) {
        return null;
    }

    $stmt->bindValue(':cod', $codCatastale, SQLITE3_TEXT);
    $stmt->bindValue(':foglio', $foglio, SQLITE3_TEXT);
    $stmt->bindValue(':part', $particella, SQLITE3_TEXT);

    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    if (!$row) {
        return null;
    }

    return [
        'ok' => true,
        'lat' => (float)$row['lat'],
        'lng' => (float)$row['lng'],
        'area_mq' => $row['area_mq'] !== null ? (float)$row['area_mq'] : null,
        'source' => 'Cache',
        'cod_catastale' => $codCatastale,
        'foglio' => $foglio,
        'particella' => $particella,
    ];
}

function saveCachedParticella(SQLite3 $db, string $codCatastale, string $foglio, string $particella, array $data): void
{
    $stmt = $db->prepare('INSERT OR REPLACE INTO particelle_cache (cod_catastale, foglio, particella, lat, lng, area_mq) VALUES (:cod, :foglio, :part, :lat, :lng, :area)');
    if (!$stmt) {
        return;
    }

    $stmt->bindValue(':cod', $codCatastale, SQLITE3_TEXT);
    $stmt->bindValue(':foglio', $foglio, SQLITE3_TEXT);
    $stmt->bindValue(':part', $particella, SQLITE3_TEXT);
    $stmt->bindValue(':lat', (float)($data['lat'] ?? 0), SQLITE3_FLOAT);
    $stmt->bindValue(':lng', (float)($data['lng'] ?? 0), SQLITE3_FLOAT);
    $stmt->bindValue(':area', isset($data['area_mq']) && $data['area_mq'] !== null ? (float)$data['area_mq'] : null, SQLITE3_FLOAT);
    $stmt->execute();
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
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'EasyCatasto-Analytics/2.0 (+https://github.com/simonedeitos/analytics)',
    ]);

    $body = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $err !== '' || $code >= 400) {
        return null;
    }

    return (string)$body;
}

function normalizeComune(string $comune): string
{
    $comune = strtoupper(trim($comune));
    $comune = preg_replace('/[_\-]+/', ' ', $comune) ?? '';
    return preg_replace('/\s+/', ' ', $comune) ?? '';
}

function normalizeProvincia(string $provincia): string
{
    $provincia = strtoupper(trim($provincia));
    return preg_replace('/[^A-Z]/', '', $provincia) ?? '';
}

function normalizeCadastralToken(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $digits = preg_replace('/\D+/', '', $value);
    if ($digits !== '') {
        $normalized = ltrim($digits, '0');
        return $normalized === '' ? '0' : $normalized;
    }

    return strtoupper($value);
}

function sendJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
