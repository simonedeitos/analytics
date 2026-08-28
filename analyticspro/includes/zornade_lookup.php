<?php

declare(strict_types=1);

/**
 * Zornade API client for cadastral parcel geolocalisation.
 *
 * Provides a cache-first lookup against the SQLite `particelle_cache` table,
 * falling back to a live request to the Zornade REST API.
 * The API key is read exclusively from the server-side .env file via
 * analyticspro_env(); it is NEVER exposed to clients or included in logs.
 *
 * NOTE: The Zornade API response schema below is based on the publicly
 * accessible documentation at https://app.zornade.com/api and the standard
 * GeoJSON/REST conventions.  If the actual field names differ, update the
 * analyticspro_zornade_extract_lat_lng() function accordingly — every field
 * access is guarded with isset() so corrections are localised.
 *
 * Assumed request format (GET):
 *   GET /particelle?comune={comune}&provincia={provincia}&foglio={foglio}&particella={particella}[&sezione={sezione}]
 *   Authorization: ******
 *
 * Assumed response (JSON, 200 OK):
 *   {
 *     "ok": true,
 *     "data": {
 *       "centroide": { "lat": 45.123, "lng": 9.456 },   // or "latitude"/"longitude"
 *       "geometry": { ... }                               // GeoJSON — ignored here
 *     }
 *   }
 * Alternative shapes also handled defensively (see analyticspro_zornade_extract_lat_lng).
 */

/**
 * Returns geocoordinates for a cadastral parcel via the Zornade API.
 *
 * Returns null (no API key configured or soft skip) or an array:
 *   ['ok' => bool, 'lat' => float|null, 'lng' => float|null, 'source' => 'Zornade']
 *
 * @param string      $comune     Nome comune (e.g. "MILANO")
 * @param string      $provincia  Sigla provincia (e.g. "MI")
 * @param string      $foglio     Foglio normalizzato (digits only)
 * @param string      $particella Particella normalizzata (digits only)
 * @param string|null $sezione    Sezione catastale opzionale
 */
function analyticspro_zornade_lookup_particella(
    string $comune,
    string $provincia,
    string $foglio,
    string $particella,
    ?string $sezione = null
): ?array {
    $apiKey  = analyticspro_env('ZORNADE_API_KEY') ?? '';
    $baseUrl = analyticspro_env('ZORNADE_API_BASE_URL') ?? 'https://app.zornade.com/api';

    // If the key is not configured, silently skip so the caller falls back to WFS.
    if (trim($apiKey) === '') {
        return null;
    }

    // Check the shared SQLite cache before making any HTTP request.
    try {
        $db     = analyticspro_wfs_open_cache_db();
        $cached = analyticspro_zornade_get_cached_particella($db, $comune, $provincia, $foglio, $particella);
        if ($cached !== null) {
            $db->close();
            return $cached;
        }
    } catch (Throwable $e) {
        $db = null;
        error_log('[Zornade] Cache open failed: ' . $e->getMessage());
    }

    // Build the API request URL.
    $params = [
        'comune'     => $comune,
        'provincia'  => $provincia,
        'foglio'     => $foglio,
        'particella' => $particella,
    ];
    if ($sezione !== null && $sezione !== '') {
        $params['sezione'] = $sezione;
    }

    $url = rtrim($baseUrl, '/') . '/particelle?' . http_build_query($params);

    $result = analyticspro_zornade_http_get($url, $apiKey);

    if ($result === null) {
        // HTTP error already logged inside analyticspro_zornade_http_get.
        if (isset($db) && $db instanceof SQLite3) {
            $db->close();
        }
        return ['ok' => false, 'lat' => null, 'lng' => null, 'source' => 'Zornade'];
    }

    [$httpCode, $body, $rateLimitRemaining, $retryAfter] = $result;

    // Handle rate-limit response.
    if ($httpCode === 429) {
        $wait = $retryAfter > 0 ? $retryAfter : 1;
        error_log("[Zornade] Rate limited (429). Retry-After: {$wait}s. Comune: {$comune}, Foglio: {$foglio}, Particella: {$particella}");
        sleep(min($wait, 60));
        if (isset($db) && $db instanceof SQLite3) {
            $db->close();
        }
        return ['ok' => false, 'lat' => null, 'lng' => null, 'source' => 'Zornade'];
    }

    if ($httpCode !== 200) {
        $excerpt = substr($body, 0, 200);
        error_log("[Zornade] HTTP {$httpCode} for {$comune}/{$foglio}/{$particella}. Body: {$excerpt}");
        if (isset($db) && $db instanceof SQLite3) {
            $db->close();
        }
        return ['ok' => false, 'lat' => null, 'lng' => null, 'source' => 'Zornade'];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        $excerpt = substr($body, 0, 200);
        error_log("[Zornade] Non-JSON response for {$comune}/{$foglio}/{$particella}. Body: {$excerpt}");
        if (isset($db) && $db instanceof SQLite3) {
            $db->close();
        }
        return ['ok' => false, 'lat' => null, 'lng' => null, 'source' => 'Zornade'];
    }

    $coords = analyticspro_zornade_extract_lat_lng($decoded);
    if ($coords === null) {
        error_log("[Zornade] Could not extract lat/lng for {$comune}/{$foglio}/{$particella}. Response keys: " . implode(', ', array_keys($decoded)));
        if (isset($db) && $db instanceof SQLite3) {
            $db->close();
        }
        return ['ok' => false, 'lat' => null, 'lng' => null, 'source' => 'Zornade'];
    }

    $resultData = [
        'ok'     => true,
        'lat'    => $coords['lat'],
        'lng'    => $coords['lng'],
        'source' => 'Zornade',
    ];

    // Persist in shared cache so the WFS cache layer is reused.
    if (isset($db) && $db instanceof SQLite3) {
        try {
            analyticspro_zornade_save_cached_particella($db, $comune, $provincia, $foglio, $particella, $resultData);
        } catch (Throwable $e) {
            error_log('[Zornade] Cache write failed: ' . $e->getMessage());
        }
        $db->close();
    }

    return $resultData;
}

/**
 * Defensively extracts lat/lng from the Zornade JSON response.
 *
 * Handles multiple plausible shapes:
 *   - data.centroide.lat / data.centroide.lng
 *   - data.centroide.latitude / data.centroide.longitude
 *   - data.lat / data.lng
 *   - lat / lng  (top-level)
 *
 * Update this function if the real API uses different field names.
 *
 * @return array{lat:float,lng:float}|null
 */
function analyticspro_zornade_extract_lat_lng(array $response): ?array
{
    // Shape 1: data.centroide.lat / data.centroide.lng
    if (isset($response['data']['centroide'])) {
        $c = $response['data']['centroide'];
        if (is_array($c)) {
            $lat = $c['lat'] ?? $c['latitude'] ?? null;
            $lng = $c['lng'] ?? $c['longitude'] ?? null;
            if (is_numeric($lat) && is_numeric($lng)) {
                return ['lat' => (float) $lat, 'lng' => (float) $lng];
            }
        }
    }

    // Shape 2: data.lat / data.lng
    if (isset($response['data']) && is_array($response['data'])) {
        $d   = $response['data'];
        $lat = $d['lat'] ?? $d['latitude'] ?? null;
        $lng = $d['lng'] ?? $d['longitude'] ?? null;
        if (is_numeric($lat) && is_numeric($lng)) {
            return ['lat' => (float) $lat, 'lng' => (float) $lng];
        }
    }

    // Shape 3: top-level lat / lng
    $lat = $response['lat'] ?? $response['latitude'] ?? null;
    $lng = $response['lng'] ?? $response['longitude'] ?? null;
    if (is_numeric($lat) && is_numeric($lng)) {
        return ['lat' => (float) $lat, 'lng' => (float) $lng];
    }

    return null;
}

/**
 * Returns a cached Zornade result or null if not in cache.
 *
 * Uses the shared particelle_cache table but keyed on comune/provincia/foglio/particella
 * because Zornade does not use cod_catastale as input.
 *
 * @return array{ok:bool,lat:float,lng:float,source:string}|null
 */
function analyticspro_zornade_get_cached_particella(
    SQLite3 $db,
    string $comune,
    string $provincia,
    string $foglio,
    string $particella
): ?array {
    // The cache table uses cod_catastale as primary lookup key; for Zornade we store
    // the comune+provincia concatenated as the cod_catastale surrogate so we can still
    // use the same table without a schema change on this column.
    $surrogate = 'ZRN_' . strtoupper($provincia) . '_' . strtoupper($comune);
    $stmt = $db->prepare(
        'SELECT lat, lng FROM particelle_cache
         WHERE cod_catastale = :cod AND foglio = :foglio AND particella = :part LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bindValue(':cod',    $surrogate, SQLITE3_TEXT);
    $stmt->bindValue(':foglio', $foglio,    SQLITE3_TEXT);
    $stmt->bindValue(':part',   $particella, SQLITE3_TEXT);
    $result = $stmt->execute();
    $row    = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;
    if (!$row) {
        return null;
    }
    return [
        'ok'     => true,
        'lat'    => (float) $row['lat'],
        'lng'    => (float) $row['lng'],
        'source' => 'Zornade',
    ];
}

/**
 * Persists a Zornade geocoding result in the shared SQLite cache.
 */
function analyticspro_zornade_save_cached_particella(
    SQLite3 $db,
    string $comune,
    string $provincia,
    string $foglio,
    string $particella,
    array $data
): void {
    $surrogate = 'ZRN_' . strtoupper($provincia) . '_' . strtoupper($comune);
    $stmt = $db->prepare(
        'INSERT OR REPLACE INTO particelle_cache (cod_catastale, foglio, particella, lat, lng, area_mq, source)
         VALUES (:cod, :foglio, :part, :lat, :lng, NULL, :source)'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bindValue(':cod',    $surrogate,               SQLITE3_TEXT);
    $stmt->bindValue(':foglio', $foglio,                  SQLITE3_TEXT);
    $stmt->bindValue(':part',   $particella,              SQLITE3_TEXT);
    $stmt->bindValue(':lat',    (float) ($data['lat'] ?? 0), SQLITE3_FLOAT);
    $stmt->bindValue(':lng',    (float) ($data['lng'] ?? 0), SQLITE3_FLOAT);
    $stmt->bindValue(':source', $data['source'] ?? 'Zornade', SQLITE3_TEXT);
    $stmt->execute();
}

/**
 * Performs a cURL GET request to the Zornade API with ******
 *
 * Returns [$httpCode, $body, $rateLimitRemaining, $retryAfter] on success,
 * or null on cURL error (already logged).
 * The API key is never included in error log messages.
 *
 * @return array{int,string,int,int}|null
 */
function analyticspro_zornade_http_get(string $url, string $apiKey): ?array
{
    if (!function_exists('curl_init')) {
        error_log('[Zornade] cURL not available');
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_USERAGENT      => 'AnalyticsPRO/2.0 (+https://github.com/simonedeitos/analytics)',
        CURLOPT_HEADER         => true, // include headers so we can parse rate-limit headers
    ]);

    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($raw === false || $err !== '') {
        error_log('[Zornade] cURL error: ' . $err);
        return null;
    }

    $headers = substr((string) $raw, 0, $headerSize);
    $body    = substr((string) $raw, $headerSize);

    // Parse rate-limit headers (case-insensitive).
    $rateLimitRemaining = -1;
    $retryAfter         = 0;
    foreach (explode("\r\n", $headers) as $line) {
        $lower = strtolower($line);
        if (str_starts_with($lower, 'x-ratelimit-remaining:')) {
            $val = trim(substr($line, strpos($line, ':') + 1));
            if (is_numeric($val)) {
                $rateLimitRemaining = (int) $val;
            }
        } elseif (str_starts_with($lower, 'retry-after:')) {
            $val = trim(substr($line, strpos($line, ':') + 1));
            if (is_numeric($val)) {
                $retryAfter = (int) $val;
            }
        }
    }

    return [$code, $body, $rateLimitRemaining, $retryAfter];
}
