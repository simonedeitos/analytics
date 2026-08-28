<?php

declare(strict_types=1);

/**
 * Shared WFS lookup logic for cadastral parcels.
 *
 * Provides cache-first lookup against the SQLite `particelle_cache` table,
 * falling back to a live query against the public AdE INSPIRE WFS service.
 * All functions accept explicit parameters (no dependency on $_GET / $_POST)
 * so they can be called both from HTTP endpoints and from the import worker.
 */

/**
 * Returns cached or live coordinates for a cadastral parcel.
 *
 * @param string $codCatastale Codice catastale del comune (e.g. "F205")
 * @param string $foglio       Foglio normalizzato (digits only, no leading zeros)
 * @param string $particella   Particella normalizzata (digits only, no leading zeros)
 * @return array{ok:bool,lat?:float,lng?:float,area_mq?:float|null,source?:string,error?:string}
 */
function analyticspro_wfs_lookup_particella(string $codCatastale, string $foglio, string $particella): array
{
    try {
        $db = analyticspro_wfs_open_cache_db();
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Errore apertura cache catasto'];
    }

    try {
        $cached = analyticspro_wfs_get_cached_particella($db, $codCatastale, $foglio, $particella);
        if ($cached !== null) {
            return $cached;
        }

        $wfsData = analyticspro_wfs_query_service($codCatastale, $foglio, $particella);
        if ($wfsData === null) {
            return ['ok' => false, 'status' => 'not_found', 'error' => 'Particella non trovata nel catasto'];
        }

        analyticspro_wfs_save_cached_particella($db, $codCatastale, $foglio, $particella, $wfsData);
        return $wfsData;
    } finally {
        $db->close();
    }
}

/**
 * Opens (or creates) the SQLite cache database.
 *
 * @throws RuntimeException if the cache directory cannot be created
 */
function analyticspro_wfs_open_cache_db(): SQLite3
{
    $dbPath = __DIR__ . '/../../cache/catasto/catasto_cache.db';
    $dbDir  = dirname($dbPath);

    if (!is_dir($dbDir) && !mkdir($dbDir, 0755, true) && !is_dir($dbDir)) {
        throw new RuntimeException('Impossibile creare directory cache catasto');
    }

    $openAndBootstrap = static function (string $path): SQLite3 {
        $db = new SQLite3($path);
        $db->busyTimeout(5000);
        $db->exec('CREATE TABLE IF NOT EXISTS particelle_cache (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        cod_catastale TEXT NOT NULL,
        foglio TEXT NOT NULL,
        particella TEXT NOT NULL,
        lat REAL NOT NULL,
        lng REAL NOT NULL,
        area_mq REAL,
        source VARCHAR(20) DEFAULT \'WFS-AdE\',
        cached_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE(cod_catastale, foglio, particella)
    )');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_particelle_cache_lookup ON particelle_cache(cod_catastale, foglio, particella)');
        return $db;
    };

    $hasColumn = static function (SQLite3 $db, string $table, string $column): bool {
        $res = $db->query('PRAGMA table_info(' . $table . ')');
        if ($res === false) {
            return false;
        }
        while (($row = $res->fetchArray(SQLITE3_ASSOC)) !== false) {
            if (strcasecmp((string) ($row['name'] ?? ''), $column) === 0) {
                return true;
            }
        }
        return false;
    };

    $db = $openAndBootstrap($dbPath);
    if (!$hasColumn($db, 'particelle_cache', 'source')) {
        $migrated = @$db->exec("ALTER TABLE particelle_cache ADD COLUMN source VARCHAR(20) DEFAULT 'WFS-AdE'");
        if (!$migrated && !$hasColumn($db, 'particelle_cache', 'source')) {
            $error = $db->lastErrorMsg();
            $db->close();
            @unlink($dbPath);
            error_log('[WFS cache] Ricreo catasto_cache.db dopo errore migrazione source: ' . $error);
            $db = $openAndBootstrap($dbPath);
        }
    }

    return $db;
}

/**
 * Returns cached coordinates or null if not in cache.
 *
 * @return array{ok:bool,lat:float,lng:float,area_mq:float|null,source:string,...}|null
 */
function analyticspro_wfs_get_cached_particella(SQLite3 $db, string $codCatastale, string $foglio, string $particella): ?array
{
    $stmt = $db->prepare('SELECT lat, lng, area_mq, source FROM particelle_cache WHERE cod_catastale = :cod AND foglio = :foglio AND particella = :part LIMIT 1');
    if (!$stmt) {
        return null;
    }

    $stmt->bindValue(':cod',    $codCatastale, SQLITE3_TEXT);
    $stmt->bindValue(':foglio', $foglio,       SQLITE3_TEXT);
    $stmt->bindValue(':part',   $particella,   SQLITE3_TEXT);

    $result = $stmt->execute();
    $row    = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    if (!$row) {
        return null;
    }

    return [
        'ok'           => true,
        'lat'          => (float) $row['lat'],
        'lng'          => (float) $row['lng'],
        'area_mq'      => $row['area_mq'] !== null ? (float) $row['area_mq'] : null,
        'source'       => $row['source'] !== null ? (string) $row['source'] : 'WFS-AdE',
        'cod_catastale'=> $codCatastale,
        'foglio'       => $foglio,
        'particella'   => $particella,
    ];
}

/**
 * Saves WFS result to the local SQLite cache.
 */
function analyticspro_wfs_save_cached_particella(SQLite3 $db, string $codCatastale, string $foglio, string $particella, array $data): void
{
    $stmt = $db->prepare('INSERT OR REPLACE INTO particelle_cache (cod_catastale, foglio, particella, lat, lng, area_mq, source) VALUES (:cod, :foglio, :part, :lat, :lng, :area, :source)');
    if (!$stmt) {
        return;
    }

    $stmt->bindValue(':cod',    $codCatastale, SQLITE3_TEXT);
    $stmt->bindValue(':foglio', $foglio,       SQLITE3_TEXT);
    $stmt->bindValue(':part',   $particella,   SQLITE3_TEXT);
    $stmt->bindValue(':lat',    (float) ($data['lat'] ?? 0), SQLITE3_FLOAT);
    $stmt->bindValue(':lng',    (float) ($data['lng'] ?? 0), SQLITE3_FLOAT);
    $stmt->bindValue(':area',   isset($data['area_mq']) && $data['area_mq'] !== null ? (float) $data['area_mq'] : null, SQLITE3_FLOAT);
    $stmt->bindValue(':source', $data['source'] ?? 'WFS-AdE', SQLITE3_TEXT);
    $stmt->execute();
}

/**
 * Queries the public AdE INSPIRE WFS service for a single cadastral parcel.
 *
 * @return array{ok:bool,lat:float,lng:float,area_mq:float|null,source:string,...}|null  null on not-found or error
 */
function analyticspro_wfs_query_service(string $codCatastale, string $foglio, string $particella): ?array
{
    $localId   = sprintf('%s.%s.%s', $codCatastale, $foglio, $particella);
    $filterXml = '<ogc:Filter xmlns:ogc="http://www.opengis.net/ogc"><ogc:PropertyIsEqualTo><ogc:PropertyName>localId</ogc:PropertyName><ogc:Literal>'
        . htmlspecialchars($localId, ENT_XML1 | ENT_COMPAT, 'UTF-8')
        . '</ogc:Literal></ogc:PropertyIsEqualTo></ogc:Filter>';

    $query = http_build_query([
        'SERVICE'      => 'WFS',
        'VERSION'      => '2.0.0',
        'REQUEST'      => 'GetFeature',
        'TYPENAME'     => 'cadastralparcel',
        'FILTER'       => $filterXml,
        'OUTPUTFORMAT' => 'application/json',
    ]);

    $url      = 'https://wfs.cartografia.agenziaentrate.gov.it/inspire/wfs/ows01.php?' . $query;
    $response = analyticspro_wfs_http_get($url);
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

    $centroid = analyticspro_wfs_centroid_from_geojson($geom);
    if ($centroid === null) {
        return null;
    }

    return [
        'ok'           => true,
        'lat'          => $centroid['lat'],
        'lng'          => $centroid['lng'],
        'area_mq'      => analyticspro_wfs_area_from_geojson($geom),
        'source'       => 'WFS-AdE',
        'cod_catastale'=> $codCatastale,
        'foglio'       => $foglio,
        'particella'   => $particella,
    ];
}

/**
 * Computes the centroid of the first ring of a GeoJSON Polygon or MultiPolygon.
 *
 * @return array{lat:float,lng:float}|null
 */
function analyticspro_wfs_centroid_from_geojson(array $geom): ?array
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
    $count  = 0;

    foreach ($ring as $point) {
        if (!is_array($point) || !isset($point[0], $point[1]) || !is_numeric($point[0]) || !is_numeric($point[1])) {
            continue;
        }
        $lngSum += (float) $point[0];
        $latSum += (float) $point[1];
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

/**
 * Approximates the area (in square metres) from a GeoJSON Polygon.
 */
function analyticspro_wfs_area_from_geojson(array $geom): ?float
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
        $current = $ring[$i]     ?? null;
        $next    = $ring[$i + 1] ?? null;
        if (!is_array($current) || !is_array($next) || !isset($current[0], $current[1], $next[0], $next[1])) {
            continue;
        }
        $area += ((float) $current[0] * (float) $next[1]) - ((float) $next[0] * (float) $current[1]);
    }

    $area           = abs($area) / 2.0;
    $metersPerDegree = 111000.0;

    return $area * ($metersPerDegree * $metersPerDegree);
}

/**
 * Performs a simple cURL GET request with sensible timeouts.
 */
function analyticspro_wfs_http_get(string $url): ?string
{
    if (!function_exists('curl_init')) {
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'EasyCatasto-Analytics/2.0 (+https://github.com/simonedeitos/analytics)',
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $err !== '' || $code >= 400) {
        return null;
    }

    return (string) $body;
}

/**
 * Normalises a comune name for key-based lookup (uppercase, collapsed whitespace).
 */
function analyticspro_wfs_normalize_comune(string $comune): string
{
    $comune = strtoupper(trim($comune));
    $comune = preg_replace('/[_\-]+/', ' ', $comune) ?? '';
    return preg_replace('/\s+/', ' ', $comune) ?? '';
}

/**
 * Normalises a two-letter provincia sigla (uppercase letters only).
 */
function analyticspro_wfs_normalize_provincia(string $provincia): string
{
    $provincia = strtoupper(trim($provincia));
    return preg_replace('/[^A-Z]/', '', $provincia) ?? '';
}

/**
 * Normalises a foglio or particella token: strips non-digits, removes leading zeros.
 */
function analyticspro_wfs_normalize_token(string $value): string
{
    $value  = trim($value);
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

/**
 * Resolves the codice catastale for a given comune+provincia pair using the
 * bundled comuni_catastali.json data file.
 *
 * The lookup is case-insensitive; the JSON is loaded and indexed once per
 * process lifetime via a static cache.
 */
function analyticspro_wfs_lookup_cod_catastale(string $comune, string $provincia): ?string
{
    static $comuniMap = null;

    if ($comuniMap === null) {
        // Acceptable paths: relative to this file (analyticspro/includes/) or the
        // repo-root data/ folder used by the standalone api/ endpoint.
        $candidates = [
            __DIR__ . '/../../data/comuni_catastali.json',
            __DIR__ . '/../../../data/comuni_catastali.json',
        ];
        $raw = null;
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                $raw = file_get_contents($candidate);
                break;
            }
        }

        $comuniMap = [];
        if ($raw !== false && $raw !== null) {
            $comuni = json_decode($raw, true);
            if (is_array($comuni)) {
                foreach ($comuni as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $nome   = analyticspro_wfs_normalize_comune((string) ($entry['nome'] ?? ''));
                    $sigla  = analyticspro_wfs_normalize_provincia((string) ($entry['sigla_provincia'] ?? ''));
                    $codice = strtoupper(trim((string) ($entry['codice_catastale'] ?? '')));
                    if ($nome === '' || $sigla === '' || $codice === '') {
                        continue;
                    }
                    $comuniMap["{$nome}|{$sigla}"] = $codice;
                }
            }
        }
    }

    $key = analyticspro_wfs_normalize_comune($comune) . '|' . analyticspro_wfs_normalize_provincia($provincia);
    return $comuniMap[$key] ?? null;
}
