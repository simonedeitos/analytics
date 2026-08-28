<?php

declare(strict_types=1);

/**
 * Zornade API v2 client for cadastral parcel geolocalisation.
 *
 * Implements the official Zornade API v2 specification (runtime 2.4.0).
 *
 * CERTAIN from official documentation:
 *   - Base URL: https://api.zornade.com/api/v2
 *   - Authentication: header `x-api-key: <token>` — NEVER Authorization: ******   - Rate limit: 1,000 requests/hour (X-RateLimit-Limit header confirms this)
 *   - Error format: { error, message } with codes 400/401/403/404/405/429/502
 *   - 429 response includes `retry_after_seconds` in body
 *   - Health endpoint: GET /health (no auth required)
 *
 * INFERRED from documentation context (verify via logs on first real run):
 *   - Search endpoint: GET /parcels/search
 *   - Search parameters: comune_code (codice catastale Belfiore), foglio, label (= particella),
 *     sezione_urbana (optional)
 *   - Search response: array of parcels, each with fid and basic.centroid.{lat,lng}
 *     OR a second call to /parcels/{fid}?include=basic may be needed if /search does
 *     not include centroid directly — both paths are handled with fallback.
 *
 * The API key is read exclusively from the server-side .env file via
 * analyticspro_env(); it is NEVER exposed to clients or included in logs.
 *
 * Field mapping:
 *   - `label` in Zornade = our "particella" number/letter visible on the cadastral map
 *   - `comune_code` = codice catastale Belfiore (e.g. "H501"), resolved from
 *     comune+provincia via analyticspro_wfs_lookup_cod_catastale()
 */

/**
 * Returns geocoordinates for a cadastral parcel via the Zornade API v2.
 *
 * Returns null (no API key configured) or an array:
 *   ['ok' => bool, 'lat' => float|null, 'lng' => float|null, 'source' => 'Zornade']
 *
 * @param string      $comune     Nome comune (e.g. "MILANO")
 * @param string      $provincia  Sigla provincia (e.g. "MI")
 * @param string      $foglio     Foglio normalizzato (digits only)
 * @param string      $particella Particella normalizzata — maps to Zornade `label` field
 * @param string|null $sezione    Sezione catastale opzionale — maps to `sezione_urbana`
 */
function analyticspro_zornade_lookup_particella(
    string $comune,
    string $provincia,
    string $foglio,
    string $particella,
    ?string $sezione = null
): ?array {
    $apiKey  = analyticspro_env('ZORNADE_API_KEY') ?? '';
    // CERTAIN: correct base URL from official documentation
    $baseUrl = analyticspro_env('ZORNADE_API_BASE_URL') ?? 'https://api.zornade.com/api/v2';

    // If the key is not configured, silently skip so the caller falls back to WFS.
    if (trim($apiKey) === '') {
        return null;
    }

    // Resolve codice catastale Belfiore (e.g. "H501") from comune+provincia.
    // CERTAIN: Zornade v2 uses comune_code (Belfiore code), not plain comune name.
    $comuneCode = analyticspro_wfs_lookup_cod_catastale($comune, $provincia);
    if ($comuneCode === null) {
        error_log("[Zornade] Cannot resolve comune_code for {$comune} ({$provincia}) — skipping Zornade lookup");
        return ['ok' => false, 'lat' => null, 'lng' => null, 'source' => 'Zornade'];
    }

    // Check the shared SQLite cache before making any HTTP request.
    // Cache key uses the real codice catastale so it is shared with WFS lookups
    // for the same parcel.
    try {
        $db     = analyticspro_wfs_open_cache_db();
        $cached = analyticspro_zornade_get_cached_particella($db, $comuneCode, $foglio, $particella);
        if ($cached !== null) {
            $db->close();
            return $cached;
        }
    } catch (Throwable $e) {
        $db = null;
        error_log('[Zornade] Cache open failed: ' . $e->getMessage());
    }

    // Build the /parcels/search request.
    // INFERRED: parameter names comune_code, foglio, label, sezione_urbana.
    // Log raw response on every call so parameter names can be corrected if needed.
    $params = [
        'comune_code' => $comuneCode,
        'foglio'      => $foglio,
        'label'       => $particella,   // label = numero/lettera particella (official doc)
    ];
    if ($sezione !== null && $sezione !== '') {
        $params['sezione_urbana'] = $sezione;  // INFERRED parameter name
    }

    $url    = rtrim($baseUrl, '/') . '/parcels/search?' . http_build_query($params);
    $result = analyticspro_zornade_http_get($url, $apiKey);

    if ($result === null) {
        // cURL error already logged inside analyticspro_zornade_http_get.
        if (isset($db) && $db instanceof SQLite3) {
            $db->close();
        }
        return ['ok' => false, 'lat' => null, 'lng' => null, 'source' => 'Zornade'];
    }

    [$httpCode, $body, $rateLimitRemaining, $retryAfter] = $result;

    // Log every response (status + body excerpt) for debuggability.
    // The API key is NEVER included in log messages.
    error_log(sprintf(
        '[Zornade] /parcels/search HTTP %d | comune_code=%s foglio=%s label=%s | RateLimit-Remaining=%s | Body(200): %s',
        $httpCode,
        $comuneCode,
        $foglio,
        $particella,
        $rateLimitRemaining >= 0 ? (string) $rateLimitRemaining : 'n/a',
        $httpCode === 200 ? substr($body, 0, 300) : '(see error log below)'
    ));

    if ($httpCode !== 200) {
        analyticspro_zornade_log_error_response($httpCode, $body, $rateLimitRemaining, $retryAfter, $comuneCode, $foglio, $particella);

        // CERTAIN: 429 includes retry_after_seconds in body — honour it.
        if ($httpCode === 429) {
            $errorData = json_decode($body, true);
            $wait      = (is_array($errorData) && isset($errorData['retry_after_seconds']) && is_numeric($errorData['retry_after_seconds']))
                ? (int) $errorData['retry_after_seconds']
                : ($retryAfter > 0 ? $retryAfter : 5);
            $wait = max(1, min($wait, 120));
            error_log("[Zornade] Sleeping {$wait}s before returning (retry_after_seconds respected)");
            sleep($wait);
        }

        if (isset($db) && $db instanceof SQLite3) {
            $db->close();
        }
        return ['ok' => false, 'lat' => null, 'lng' => null, 'source' => 'Zornade'];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        error_log("[Zornade] Non-JSON response for comune_code={$comuneCode} foglio={$foglio} label={$particella}. Body: " . substr($body, 0, 200));
        if (isset($db) && $db instanceof SQLite3) {
            $db->close();
        }
        return ['ok' => false, 'lat' => null, 'lng' => null, 'source' => 'Zornade'];
    }

    // INFERRED: /parcels/search returns an array/list of parcels.
    // Each parcel has at least `fid` and may include `basic.centroid.{lat,lng}`.
    $parcels = $decoded;
    // Some APIs wrap the list; try common envelope keys if top-level is not an array.
    if (!isset($parcels[0]) && isset($decoded['data']) && is_array($decoded['data'])) {
        $parcels = $decoded['data'];
    } elseif (!isset($parcels[0]) && isset($decoded['results']) && is_array($decoded['results'])) {
        $parcels = $decoded['results'];
    }

    if (!is_array($parcels) || count($parcels) === 0) {
        error_log("[Zornade] No parcels found for comune_code={$comuneCode} foglio={$foglio} label={$particella}");
        if (isset($db) && $db instanceof SQLite3) {
            $db->close();
        }
        return ['ok' => false, 'lat' => null, 'lng' => null, 'source' => 'Zornade'];
    }

    $firstParcel = $parcels[0];
    if (!is_array($firstParcel)) {
        error_log("[Zornade] Unexpected parcel format for comune_code={$comuneCode} foglio={$foglio} label={$particella}");
        if (isset($db) && $db instanceof SQLite3) {
            $db->close();
        }
        return ['ok' => false, 'lat' => null, 'lng' => null, 'source' => 'Zornade'];
    }

    // Path A: /parcels/search already includes centroid in response.
    // CERTAIN (from /parcels/{id}?include=basic doc): centroid is at basic.centroid.{lat,lng}
    $coords = analyticspro_zornade_extract_lat_lng($firstParcel);

    // Path B: /search does not include centroid — fetch /parcels/{fid}?include=basic.
    // INFERRED: fid is the numeric internal Zornade parcel ID.
    if ($coords === null && isset($firstParcel['fid'])) {
        $fid        = (int) $firstParcel['fid'];
        $detailUrl  = rtrim($baseUrl, '/') . '/parcels/' . $fid . '?include=basic';
        $detailResult = analyticspro_zornade_http_get($detailUrl, $apiKey);

        if ($detailResult !== null) {
            [$dCode, $dBody, $dRlRemaining, $dRetryAfter] = $detailResult;
            error_log(sprintf(
                '[Zornade] /parcels/%d?include=basic HTTP %d | RateLimit-Remaining=%s | Body: %s',
                $fid,
                $dCode,
                $dRlRemaining >= 0 ? (string) $dRlRemaining : 'n/a',
                $dCode === 200 ? substr($dBody, 0, 300) : substr($dBody, 0, 200)
            ));

            if ($dCode === 200) {
                $dDecoded = json_decode($dBody, true);
                if (is_array($dDecoded)) {
                    $coords = analyticspro_zornade_extract_lat_lng($dDecoded);
                }
            } else {
                analyticspro_zornade_log_error_response($dCode, $dBody, $dRlRemaining, $dRetryAfter, $comuneCode, $foglio, $particella);
            }
        }
    }

    if ($coords === null) {
        $topKeys = implode(', ', array_keys($firstParcel));
        error_log("[Zornade] Could not extract lat/lng for comune_code={$comuneCode} foglio={$foglio} label={$particella}. First parcel keys: {$topKeys}");
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

    // Persist in shared cache (keyed by real codice catastale for interoperability with WFS).
    if (isset($db) && $db instanceof SQLite3) {
        try {
            analyticspro_zornade_save_cached_particella($db, $comuneCode, $foglio, $particella, $resultData);
        } catch (Throwable $e) {
            error_log('[Zornade] Cache write failed: ' . $e->getMessage());
        }
        $db->close();
    }

    return $resultData;
}

/**
 * Calls GET /health (no authentication required) and returns the parsed response.
 *
 * Useful for a future "Test Zornade connection" button in admin.
 * Does NOT consume rate-limit quota.
 *
 * CERTAIN: endpoint exists at /health with no auth per official documentation.
 *
 * @return array{status:string,...}|null  null on connection error
 */
function analyticspro_zornade_health_check(): ?array
{
    $baseUrl = analyticspro_env('ZORNADE_API_BASE_URL') ?? 'https://api.zornade.com/api/v2';
    $url     = rtrim($baseUrl, '/') . '/health';

    if (!function_exists('curl_init')) {
        error_log('[Zornade] cURL not available for health check');
        return null;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        CURLOPT_USERAGENT      => 'AnalyticsPRO/2.0 (+https://github.com/simonedeitos/analytics)',
    ]);

    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $err !== '') {
        error_log('[Zornade] Health check cURL error: ' . $err);
        return null;
    }

    $decoded = json_decode((string) $body, true);
    error_log("[Zornade] /health HTTP {$code} | Body: " . substr((string) $body, 0, 200));

    if (!is_array($decoded)) {
        return ['status' => 'unknown', 'http_code' => $code, 'raw' => substr((string) $body, 0, 200)];
    }

    $decoded['http_code'] = $code;
    return $decoded;
}

/**
 * Logs a non-200 Zornade API error with full context.
 * Handles all documented error codes: 400/401/403/404/405/429/502.
 * The API key is NEVER included in log messages.
 */
function analyticspro_zornade_log_error_response(
    int $httpCode,
    string $body,
    int $rateLimitRemaining,
    int $retryAfter,
    string $comuneCode,
    string $foglio,
    string $particella
): void {
    $errorData = json_decode($body, true);
    $errorCode = is_array($errorData) ? ($errorData['error'] ?? 'unknown') : 'non-json';
    $message   = is_array($errorData) ? ($errorData['message'] ?? '') : substr($body, 0, 200);
    $retryInfo = isset($errorData['retry_after_seconds']) ? (' retry_after_seconds=' . $errorData['retry_after_seconds']) : '';
    $rlInfo    = $rateLimitRemaining >= 0 ? " RateLimit-Remaining={$rateLimitRemaining}" : '';

    $context = [
        400 => 'INVALID_PARAMS or OUT_OF_BOUNDS',
        401 => 'API_KEY_REQUIRED or INVALID_API_KEY',
        403 => 'INSUFFICIENT_SCOPE',
        404 => 'NOT_FOUND',
        405 => 'Method not allowed (must be GET)',
        429 => 'RATE_LIMITED',
        502 => 'QUERY_ERROR (server-side)',
    ];
    $desc = $context[$httpCode] ?? 'unexpected status';

    error_log(sprintf(
        '[Zornade] HTTP %d (%s) | error=%s message=%s | comune_code=%s foglio=%s label=%s%s%s',
        $httpCode,
        $desc,
        $errorCode,
        $message,
        $comuneCode,
        $foglio,
        $particella,
        $rlInfo,
        $retryInfo
    ));
}

/**
 * Defensively extracts lat/lng from a Zornade parcel object.
 *
 * CERTAIN (from /parcels/{id}?include=basic documentation):
 *   centroid is at basic.centroid.{lat, lng} (WGS84 / EPSG:4326)
 *
 * INFERRED fallbacks for /parcels/search response variants:
 *   - centroid.{lat,lng} directly on the parcel object
 *   - top-level lat/lng
 *
 * Update this function once the real /parcels/search response shape is confirmed via logs.
 *
 * @return array{lat:float,lng:float}|null
 */
function analyticspro_zornade_extract_lat_lng(array $parcel): ?array
{
    // CERTAIN shape from /parcels/{id}?include=basic: basic.centroid.lat / basic.centroid.lng
    if (isset($parcel['basic']['centroid']) && is_array($parcel['basic']['centroid'])) {
        $c   = $parcel['basic']['centroid'];
        $lat = $c['lat'] ?? $c['latitude'] ?? null;
        $lng = $c['lng'] ?? $c['longitude'] ?? null;
        if (is_numeric($lat) && is_numeric($lng)) {
            return ['lat' => (float) $lat, 'lng' => (float) $lng];
        }
    }

    // INFERRED: /parcels/search may return centroid directly on the object
    if (isset($parcel['centroid']) && is_array($parcel['centroid'])) {
        $c   = $parcel['centroid'];
        $lat = $c['lat'] ?? $c['latitude'] ?? null;
        $lng = $c['lng'] ?? $c['longitude'] ?? null;
        if (is_numeric($lat) && is_numeric($lng)) {
            return ['lat' => (float) $lat, 'lng' => (float) $lng];
        }
    }

    // INFERRED: top-level lat/lng on the parcel object
    $lat = $parcel['lat'] ?? $parcel['latitude'] ?? null;
    $lng = $parcel['lng'] ?? $parcel['longitude'] ?? null;
    if (is_numeric($lat) && is_numeric($lng)) {
        return ['lat' => (float) $lat, 'lng' => (float) $lng];
    }

    return null;
}

/**
 * Returns a cached Zornade result or null if not in cache.
 *
 * Uses the shared particelle_cache table keyed by the real codice catastale
 * (e.g. "H501") so the cache is interoperable with WFS lookups.
 *
 * @return array{ok:bool,lat:float,lng:float,source:string}|null
 */
function analyticspro_zornade_get_cached_particella(
    SQLite3 $db,
    string $comuneCode,
    string $foglio,
    string $particella
): ?array {
    $stmt = $db->prepare(
        'SELECT lat, lng FROM particelle_cache
         WHERE cod_catastale = :cod AND foglio = :foglio AND particella = :part LIMIT 1'
    );
    if (!$stmt) {
        return null;
    }
    $stmt->bindValue(':cod',    $comuneCode, SQLITE3_TEXT);
    $stmt->bindValue(':foglio', $foglio,     SQLITE3_TEXT);
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
 * Keyed by the real codice catastale for interoperability with WFS.
 */
function analyticspro_zornade_save_cached_particella(
    SQLite3 $db,
    string $comuneCode,
    string $foglio,
    string $particella,
    array $data
): void {
    $stmt = $db->prepare(
        'INSERT OR REPLACE INTO particelle_cache (cod_catastale, foglio, particella, lat, lng, area_mq, source)
         VALUES (:cod, :foglio, :part, :lat, :lng, NULL, :source)'
    );
    if (!$stmt) {
        return;
    }
    $stmt->bindValue(':cod',    $comuneCode,               SQLITE3_TEXT);
    $stmt->bindValue(':foglio', $foglio,                   SQLITE3_TEXT);
    $stmt->bindValue(':part',   $particella,               SQLITE3_TEXT);
    $stmt->bindValue(':lat',    (float) ($data['lat'] ?? 0), SQLITE3_FLOAT);
    $stmt->bindValue(':lng',    (float) ($data['lng'] ?? 0), SQLITE3_FLOAT);
    $stmt->bindValue(':source', $data['source'] ?? 'Zornade', SQLITE3_TEXT);
    $stmt->execute();
}

/**
 * Performs a cURL GET request to the Zornade API v2.
 *
 * CERTAIN: authentication is via `x-api-key` header only.
 * NEVER use `Authorization: Bearer` — the official documentation explicitly forbids it
 * and will return UNAUTHORIZED_NO_AUTH_HEADER or UNAUTHORIZED_INVALID_JWT_FORMAT.
 *
 * Rate limit: 1,000 req/hour (CERTAIN from official docs).
 * For a single import batch of a few dozen parcels this limit is not a concern.
 * The minimum inter-call gap applied in the import worker (500 ms) is a conservative
 * practical safeguard, not an attempt to precisely distribute the hourly quota.
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
        // CERTAIN: x-api-key header is the only accepted authentication method in API v2.
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'x-api-key: ' . $apiKey,
        ],
        CURLOPT_USERAGENT      => 'AnalyticsPRO/2.0 (+https://github.com/simonedeitos/analytics)',
        CURLOPT_HEADER         => true, // include response headers for rate-limit parsing
    ]);

    $raw        = curl_exec($ch);
    $err        = curl_error($ch);
    $code       = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($raw === false || $err !== '') {
        error_log('[Zornade] cURL error: ' . $err);
        return null;
    }

    $headers = substr((string) $raw, 0, $headerSize);
    $body    = substr((string) $raw, $headerSize);

    // Parse rate-limit response headers (CERTAIN: X-RateLimit-Limit/Remaining/Reset).
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
