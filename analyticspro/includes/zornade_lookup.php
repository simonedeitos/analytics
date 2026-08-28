<?php

declare(strict_types=1);

/**
 * Zornade API client for cadastral parcel geolocation.
 *
 * Zornade (https://app.zornade.com/api) is the primary geolocation provider.
 * Given comune/foglio/particella it returns the parcel centroid (lat/lng) plus
 * additional zone attributes (OMI, seismic risk, etc.) — only lat/lng is used
 * here; the extra zone data is reserved for a future import step.
 *
 * Assumptions about the Zornade API (verify against your authenticated docs and
 * adjust the constants/parsing below if the real schema differs):
 *   - Endpoint: GET {ZORNADE_API_BASE_URL}/parcels
 *   - Query params: comune, provincia, foglio, particella, sezione (optional)
 *   - Auth: Authorization: ******
 *   - Response JSON contains a top-level "data" object (or array[0]) with at
 *     least "lat" and "lng" numeric fields (or "latitude"/"longitude").
 *     If the real field names differ, update ZORNADE_LAT_FIELDS / ZORNADE_LNG_FIELDS.
 *
 * Functions follow the same conventions as wfs_lookup.php:
 *   - Pure functions, explicit parameters, no dependency on $_GET/$_POST.
 *   - Prefix: analyticspro_zornade_*
 *   - Returns a shape compatible with analyticspro_wfs_query_service():
 *     ['ok' => bool, 'lat' => float|null, 'lng' => float|null, 'source' => 'Zornade', ...]
 */

/** Candidate field names for latitude in Zornade response (most specific first). */
const ZORNADE_LAT_FIELDS = ['lat', 'latitude', 'centroid_lat', 'y'];

/** Candidate field names for longitude in Zornade response (most specific first). */
const ZORNADE_LNG_FIELDS = ['lng', 'longitude', 'centroid_lng', 'x'];

/**
 * Looks up a cadastral parcel via Zornade API.
 *
 * Returns null if the API key is not configured (callers should fall back to WFS).
 * Returns ['ok' => false, ...] on HTTP/parsing error (callers should also fall back).
 * Returns ['ok' => true, 'lat' => float, 'lng' => float, 'source' => 'Zornade', ...]
 * on success.
 *
 * Rate-limiting: a minimum gap of ~175 ms is enforced between consecutive live calls
 * (safe margin under the 10 000 req/hour ≈ 2.77/s limit).  If the response carries
 * X-RateLimit-Remaining or Retry-After headers, those take precedence.
 *
 * @param string      $comune     Nome del comune (es. "CALCINATO")
 * @param string      $provincia  Sigla provincia (es. "BS")
 * @param string      $foglio     Numero foglio (es. "34")
 * @param string      $particella Numero particella (es. "351")
 * @param string|null $sezione    Sezione catastale opzionale
 */
function analyticspro_zornade_lookup_particella(
    string $comune,
    string $provincia,
    string $foglio,
    string $particella,
    ?string $sezione = null
): ?array {
    $apiKey  = analyticspro_zornade_api_key();
    $baseUrl = analyticspro_zornade_base_url();

    // If the key is not configured, return null silently so the caller falls back.
    if ($apiKey === null || $apiKey === '') {
        return null;
    }

    $params = [
        'comune'     => $comune,
        'provincia'  => $provincia,
        'foglio'     => $foglio,
        'particella' => $particella,
    ];
    if ($sezione !== null && $sezione !== '') {
        $params['sezione'] = $sezione;
    }

    $url = rtrim($baseUrl, '/') . '/parcels?' . http_build_query($params);

    [$body, $httpCode, $retryAfter] = analyticspro_zornade_http_get($url, $apiKey);

    // Respect Retry-After if present (we already slept the minimum gap before calling).
    if ($retryAfter > 0) {
        usleep((int) ($retryAfter * 1_000_000));
    }

    if ($body === null) {
        error_log(sprintf('[Zornade] HTTP %d fetching parcel %s/%s/%s/%s', $httpCode, $comune, $provincia, $foglio, $particella));
        return ['ok' => false, 'source' => 'Zornade', 'error' => "HTTP {$httpCode}"];
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        $excerpt = substr($body, 0, 200);
        error_log("[Zornade] Non-JSON response for {$comune}/{$foglio}/{$particella}: {$excerpt}");
        return ['ok' => false, 'source' => 'Zornade', 'error' => 'Non-JSON response'];
    }

    // Extract the parcel record — try common envelope shapes.
    $record = analyticspro_zornade_extract_record($json);
    if ($record === null) {
        error_log("[Zornade] Unexpected JSON structure for {$comune}/{$foglio}/{$particella}: " . substr($body, 0, 300));
        return ['ok' => false, 'source' => 'Zornade', 'error' => 'Parcel record not found in response'];
    }

    $lat = analyticspro_zornade_extract_float($record, ZORNADE_LAT_FIELDS);
    $lng = analyticspro_zornade_extract_float($record, ZORNADE_LNG_FIELDS);

    if ($lat === null || $lng === null) {
        error_log("[Zornade] lat/lng missing for {$comune}/{$foglio}/{$particella}: " . substr($body, 0, 300));
        return ['ok' => false, 'source' => 'Zornade', 'error' => 'lat/lng fields missing'];
    }

    // Basic sanity check: Italian coordinates (roughly).
    if ($lat < 35.0 || $lat > 48.0 || $lng < 6.0 || $lng > 19.0) {
        error_log("[Zornade] Coordinates out of Italian bounds for {$comune}/{$foglio}/{$particella}: lat={$lat} lng={$lng}");
        return ['ok' => false, 'source' => 'Zornade', 'error' => 'Coordinates out of bounds'];
    }

    return [
        'ok'         => true,
        'lat'        => $lat,
        'lng'        => $lng,
        'area_mq'    => null,
        'source'     => 'Zornade',
        'comune'     => $comune,
        'provincia'  => $provincia,
        'foglio'     => $foglio,
        'particella' => $particella,
    ];
}

/**
 * Returns the Zornade API key from environment, or null if not configured.
 * Never logs the key value.
 */
function analyticspro_zornade_api_key(): ?string
{
    $key = analyticspro_env('ZORNADE_API_KEY');
    if ($key === null || trim($key) === '') {
        return null;
    }
    return trim($key);
}

/**
 * Returns the Zornade API base URL from environment, defaulting to the official URL.
 */
function analyticspro_zornade_base_url(): string
{
    $url = analyticspro_env('ZORNADE_API_BASE_URL', 'https://app.zornade.com/api');
    return rtrim((string) $url, '/');
}

/**
 * Performs a cURL GET request with ****** and returns [body|null, httpCode, retryAfter].
 *
 * @return array{0: string|null, 1: int, 2: float}
 */
function analyticspro_zornade_http_get(string $url, string $apiKey): array
{
    if (!function_exists('curl_init')) {
        return [null, 0, 0.0];
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
            // The API key is sent only as a header, never logged or exposed.
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_USERAGENT      => 'EasyCatasto-Analytics/2.0 (+https://github.com/simonedeitos/analytics)',
        CURLOPT_HEADER         => true,  // include headers in output to read rate-limit info
    ]);

    $raw        = curl_exec($ch);
    $curlErr    = curl_error($ch);
    $httpCode   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    if ($raw === false || $curlErr !== '') {
        error_log('[Zornade] cURL error: ' . $curlErr);
        return [null, 0, 0.0];
    }

    $raw        = (string) $raw;
    $headers    = substr($raw, 0, $headerSize);
    $body       = substr($raw, $headerSize);
    $retryAfter = 0.0;

    // Respect Retry-After (seconds) or X-RateLimit-Remaining: 0.
    if (preg_match('/^Retry-After:\s*(\d+(?:\.\d+)?)/im', $headers, $m)) {
        $retryAfter = (float) $m[1];
    } elseif (preg_match('/^X-RateLimit-Remaining:\s*0\b/im', $headers)) {
        $retryAfter = 1.0;
    }

    if ($httpCode >= 400) {
        $excerpt = substr($body, 0, 200);
        error_log("[Zornade] HTTP {$httpCode}: {$excerpt}");
        return [null, $httpCode, $retryAfter];
    }

    return [$body, $httpCode, $retryAfter];
}

/**
 * Extracts the parcel record from a variety of common Zornade response envelopes:
 *   { "data": { ...record... } }
 *   { "data": [ { ...record... }, ... ] }
 *   { "result": { ...record... } }
 *   { "parcel": { ...record... } }
 *   { "features": [ { "properties": { ...record... } } ] }  (GeoJSON)
 *   { "lat": ..., "lng": ... }  (flat)
 *
 * Returns null if no record can be extracted.
 */
function analyticspro_zornade_extract_record(array $json): ?array
{
    // GeoJSON FeatureCollection
    if (isset($json['type']) && $json['type'] === 'FeatureCollection' && isset($json['features'][0]['properties'])) {
        $props = $json['features'][0]['properties'];
        // Also attempt to pull centroid from geometry if present.
        $geom = $json['features'][0]['geometry'] ?? null;
        if (is_array($geom) && isset($geom['coordinates'])) {
            $coords = $geom['coordinates'];
            if (($geom['type'] ?? '') === 'Point' && isset($coords[0], $coords[1])) {
                $props['lng'] = $props['lng'] ?? $coords[0];
                $props['lat'] = $props['lat'] ?? $coords[1];
            }
        }
        return is_array($props) ? $props : null;
    }

    // Common envelope keys
    foreach (['data', 'result', 'parcel', 'parcella'] as $key) {
        if (!isset($json[$key])) {
            continue;
        }
        $val = $json[$key];
        if (is_array($val) && array_is_list($val) && count($val) > 0 && is_array($val[0])) {
            return $val[0];
        }
        if (is_array($val) && !array_is_list($val)) {
            return $val;
        }
    }

    // Flat response (direct lat/lng at top level)
    $hasCoords = false;
    foreach (array_merge(ZORNADE_LAT_FIELDS, ZORNADE_LNG_FIELDS) as $field) {
        if (isset($json[$field]) && is_numeric($json[$field])) {
            $hasCoords = true;
            break;
        }
    }
    if ($hasCoords) {
        return $json;
    }

    return null;
}

/**
 * Extracts the first matching float from an array using a list of candidate keys.
 *
 * @param string[] $keys
 */
function analyticspro_zornade_extract_float(array $record, array $keys): ?float
{
    foreach ($keys as $key) {
        if (isset($record[$key]) && is_numeric($record[$key])) {
            return (float) $record[$key];
        }
    }
    return null;
}

/**
 * Enforces a minimum gap between consecutive Zornade API calls.
 *
 * Pass and receive the timestamp (microtime(true)) of the last call; this
 * function sleeps if needed and always returns the current time for the next
 * iteration.
 *
 * @param float $lastCallAt  Timestamp of previous call, 0.0 if none yet.
 * @return float             Current timestamp (to store for next call).
 */
function analyticspro_zornade_rate_limit(float $lastCallAt): float
{
    $minGap = 0.175; // 175 ms → ≈ 5.7 req/s, well within 10 000/hour limit
    if ($lastCallAt > 0.0) {
        $elapsed = microtime(true) - $lastCallAt;
        if ($elapsed < $minGap) {
            usleep((int) (($minGap - $elapsed) * 1_000_000));
        }
    }
    return microtime(true);
}
