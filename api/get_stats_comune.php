<?php
declare(strict_types=1);

/**
 * get_stats_comune.php
 * Proxy API for Italian municipal statistics.
 *
 * GET ?comune=Roma&provincia=RM
 *
 * Data sources (attempted in order):
 *   1. Local cache (7 days TTL) in cache/stats_comuni/{comune_slug}.json
 *   2. Cruscotto Italia / dati.gov.it public API
 *   3. Stub/fallback data when all sources are unavailable
 *
 * Returns JSON with demographics, economy and real-estate data.
 */

date_default_timezone_set('Europe/Rome');
set_time_limit(30);

define('STATS_CACHE_DIR',    __DIR__ . '/../cache/stats_comuni');
define('STATS_CACHE_TTL',    7 * 24 * 3600);   // 7 days
define('STATS_HTTP_TIMEOUT', 10);               // seconds per HTTP request

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$comune   = trim((string)($_GET['comune']   ?? ''));
$provincia = strtoupper(trim((string)($_GET['provincia'] ?? '')));

if ($comune === '' || $provincia === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Parametri comune e provincia obbligatori'], JSON_UNESCAPED_UNICODE);
    exit;
}

ensureStatsCacheDir();

$cacheKey  = slugify($comune) . '_' . strtolower($provincia);
$cachePath = STATS_CACHE_DIR . "/{$cacheKey}.json";

// Serve from local cache if still fresh
if (file_exists($cachePath) && (time() - filemtime($cachePath)) < STATS_CACHE_TTL) {
    $cached = file_get_contents($cachePath);
    if ($cached !== false) {
        echo $cached;
        exit;
    }
}

// Attempt to fetch live data
$data = fetchStatsForComune($comune, $provincia);

// Persist to cache (best-effort)
@file_put_contents($cachePath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

echo json_encode($data, JSON_UNESCAPED_UNICODE);
exit;

// ─────────────────────────────────────────────────────────────────────────────

function fetchStatsForComune(string $comune, string $provincia): array
{
    // Build the base response with metadata we always know
    $result = [
        'ok'                 => true,
        'nome'               => strtoupper($comune),
        'provincia'          => $provincia,
        'regione'            => null,
        'codice_catastale'   => null,
        'demografia'         => null,
        'economia'           => null,
        'immobiliare'        => null,
        'fonte_dati'         => 'dati.gov.it / Cruscotto Italia',
        'ultimo_aggiornamento' => date('Y-m-d'),
        'data_source'        => 'live',
    ];

    // Try Cruscotto Italia MCP / dati.gov.it
    $apiData = fetchCruscottoItalia($comune, $provincia);
    if ($apiData !== null) {
        return array_merge($result, $apiData, ['data_source' => 'cruscotto_italia']);
    }

    // Fallback: return stub structure so the UI can still render
    logStatsError("API non disponibile per $comune ($provincia) – usando dati stub");

    $result['data_source'] = 'stub';
    $result['notice']      = 'Dati statistici non disponibili al momento. Riprovare più tardi.';
    $result['demografia']  = buildDemografiaStub();
    $result['economia']    = buildEconomiaStub();
    $result['immobiliare'] = buildImmobiliareStub();

    return $result;
}

/**
 * Query the Cruscotto Italia public API at dati.gov.it.
 *
 * The Cruscotto Italia endpoint provides aggregate statistics per commune.
 * We attempt two known URL patterns. Returns null if no live data could be retrieved.
 */
function fetchCruscottoItalia(string $comune, string $provincia): ?array
{
    // Pattern 1: OpenData API with comune + provincia search
    $encoded  = urlencode(strtoupper($comune));
    $encodedP = urlencode(strtoupper($provincia));

    $urls = [
        "https://cruscotto-italia.dati.gov.it/api/v1/comuni?nome={$encoded}&provincia={$encodedP}&limit=1",
        "https://cruscotto-italia.dati.gov.it/api/comuni/search?q={$encoded}&prov={$encodedP}",
    ];

    foreach ($urls as $url) {
        $raw = httpGet($url);
        if ($raw === null) {
            continue;
        }

        $json = json_decode($raw, true);
        if (!is_array($json)) {
            continue;
        }

        $parsed = parseCruscottoResponse($json, $comune, $provincia);
        if ($parsed !== null) {
            return $parsed;
        }
    }

    return null;
}

/**
 * Parse a Cruscotto Italia API response and map it to our internal structure.
 * Returns null if the response doesn't contain usable data.
 */
function parseCruscottoResponse(array $json, string $comune, string $provincia): ?array
{
    // Handle both array-of-results and single-object responses
    $item = null;

    if (isset($json['data']) && is_array($json['data'])) {
        $item = $json['data'][0] ?? null;
    } elseif (isset($json['results']) && is_array($json['results'])) {
        $item = $json['results'][0] ?? null;
    } elseif (isset($json['nome']) || isset($json['comune'])) {
        $item = $json;
    } elseif (is_array($json) && isset($json[0])) {
        $item = $json[0];
    }

    if (!is_array($item)) {
        return null;
    }

    // Verify we got the right commune (basic sanity check)
    $apiName = strtoupper((string)($item['nome'] ?? $item['comune'] ?? $item['COMUNE'] ?? ''));
    if ($apiName !== '' && stripos($apiName, $comune) === false && stripos($comune, $apiName) === false) {
        return null;
    }

    return [
        'regione'          => $item['regione'] ?? $item['REGIONE'] ?? null,
        'codice_catastale' => $item['codice_catastale'] ?? $item['codice_belfiore'] ?? null,
        'demografia' => [
            'popolazione'       => intOrNull($item, ['popolazione', 'pop_totale', 'POP_TOT']),
            'densita_kmq'       => floatOrNull($item, ['densita_kmq', 'densita', 'DENSITA']),
            'fasce_eta'         => parseFasceEta($item),
            'trend_crescita_pct'=> floatOrNull($item, ['trend_crescita', 'variazione_pop']),
        ],
        'economia' => [
            'reddito_medio_irpef'  => floatOrNull($item, ['reddito_medio', 'reddito_irpef', 'RED_MEDIO']),
            'numero_imprese'       => intOrNull($item, ['imprese', 'num_imprese', 'N_IMPRESE']),
            'settori_principali'   => parseSettori($item),
            'tasso_disoccupazione' => floatOrNull($item, ['disoccupazione', 'tasso_disoc']),
        ],
        'immobiliare' => [
            'prezzo_mq_medio_residenziale' => floatOrNull($item, ['prezzo_mq_res', 'quotazione_res', 'QUO_RES']),
            'prezzo_mq_medio_commerciale'  => floatOrNull($item, ['prezzo_mq_com', 'quotazione_com', 'QUO_COM']),
            'transazioni_anno'             => intOrNull($item, ['transazioni', 'nmt', 'NMT']),
            'fonte'                        => 'OMI - Agenzia delle Entrate',
        ],
    ];
}

// ─── Helpers for API response mapping ────────────────────────────────────────

function intOrNull(array $item, array $keys): ?int
{
    foreach ($keys as $k) {
        if (isset($item[$k]) && is_numeric($item[$k])) {
            return (int)$item[$k];
        }
    }
    return null;
}

function floatOrNull(array $item, array $keys): ?float
{
    foreach ($keys as $k) {
        if (isset($item[$k]) && is_numeric($item[$k])) {
            return (float)$item[$k];
        }
    }
    return null;
}

function parseFasceEta(array $item): ?array
{
    $keys = [
        '0-14'  => ['perc_0_14',  'fascia_0_14',  'P_014'],
        '15-64' => ['perc_15_64', 'fascia_15_64', 'P_1564'],
        '65+'   => ['perc_65_',   'fascia_65p',   'P_65P'],
    ];

    $result = [];
    foreach ($keys as $label => $candidates) {
        $val = floatOrNull($item, $candidates);
        if ($val !== null) {
            $result[$label] = $val;
        }
    }

    return $result !== [] ? $result : null;
}

function parseSettori(array $item): array
{
    $raw = $item['settori'] ?? $item['settori_principali'] ?? '';
    if (is_array($raw)) {
        return array_values($raw);
    }
    if (is_string($raw) && $raw !== '') {
        return array_map('trim', explode(',', $raw));
    }
    return [];
}

// ─── Stub builders (used when API is unavailable) ────────────────────────────

function buildDemografiaStub(): array
{
    return [
        'popolazione'       => null,
        'densita_kmq'       => null,
        'fasce_eta'         => null,
        'trend_crescita_pct'=> null,
    ];
}

function buildEconomiaStub(): array
{
    return [
        'reddito_medio_irpef'  => null,
        'numero_imprese'       => null,
        'settori_principali'   => [],
        'tasso_disoccupazione' => null,
    ];
}

function buildImmobiliareStub(): array
{
    return [
        'prezzo_mq_medio_residenziale' => null,
        'prezzo_mq_medio_commerciale'  => null,
        'transazioni_anno'             => null,
        'fonte'                        => 'OMI - Agenzia delle Entrate',
    ];
}

// ─── HTTP helpers ─────────────────────────────────────────────────────────────

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
        CURLOPT_TIMEOUT        => STATS_HTTP_TIMEOUT,
        CURLOPT_USERAGENT      => 'EasyCatasto-Analytics/1.0 (+https://github.com/simonedeitos/analytics)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);

    $response  = curl_exec($ch);
    $httpCode  = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError !== '' || $httpCode >= 400) {
        return null;
    }

    return (string)$response;
}

// ─── Utility ──────────────────────────────────────────────────────────────────

function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '_', $text) ?? $text;
    return trim($text, '_');
}

function ensureStatsCacheDir(): void
{
    if (!is_dir(STATS_CACHE_DIR) && !mkdir(STATS_CACHE_DIR, 0755, true) && !is_dir(STATS_CACHE_DIR)) {
        // Non-fatal: cache unavailable but request can still proceed
    }
}

function logStatsError(string $message): void
{
    $logFile = STATS_CACHE_DIR . '/errors.log';
    $line    = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}
