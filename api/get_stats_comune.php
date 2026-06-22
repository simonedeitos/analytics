<?php
declare(strict_types=1);

/**
 * get_stats_comune.php
 * Statistiche comunali: usa API pubblica ISTAT SDMX per la demografia.
 * Economia e immobiliare sono restituiti come non disponibili via API pubblica.
 */

date_default_timezone_set('Europe/Rome');
set_time_limit(30);

const STATS_CACHE_DIR = __DIR__ . '/../cache/stats_comuni';
const STATS_CACHE_TTL = 7 * 24 * 3600;

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$comune = normalizeComune((string)($_GET['comune'] ?? ''));
$provincia = normalizeProvincia((string)($_GET['provincia'] ?? ''));

if ($comune === '' || $provincia === '') {
    sendJson(['ok' => false, 'error' => 'Parametri comune e provincia obbligatori'], 400);
}

ensureStatsCacheDir();
$cacheKey = slugify($comune) . '_' . strtolower($provincia);
$cachePath = STATS_CACHE_DIR . "/{$cacheKey}.json";

if (is_file($cachePath) && (time() - (int)filemtime($cachePath)) < STATS_CACHE_TTL) {
    $cached = file_get_contents($cachePath);
    if ($cached !== false) {
        echo $cached;
        exit;
    }
}

$data = fetchStatsComune($comune, $provincia);
@file_put_contents($cachePath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

sendJson($data);

function fetchStatsComune(string $comune, string $provincia): array
{
    $meta = lookupComuneMeta($comune, $provincia);
    $codiceIstat = $meta['codice_istat'] ?? null;

    $popolazione = null;
    $demografiaFonte = 'ISTAT (non disponibile)';

    if ($codiceIstat !== null) {
        $popolazione = fetchPopulationFromISTAT($codiceIstat);
        if ($popolazione !== null) {
            $demografiaFonte = 'ISTAT SDMX';
        }
    }

    return [
        'ok' => true,
        'nome' => $comune,
        'provincia' => $provincia,
        'regione' => $meta['regione'] ?? null,
        'codice_catastale' => $meta['codice_catastale'] ?? null,
        'codice_istat' => $codiceIstat,
        'demografia' => [
            'popolazione' => $popolazione,
            'densita_kmq' => null,
            'fasce_eta' => null,
            'trend_crescita_pct' => null,
            'fonte' => $demografiaFonte,
        ],
        'economia' => [
            'reddito_medio_irpef' => null,
            'numero_imprese' => null,
            'settori_principali' => [],
            'tasso_disoccupazione' => null,
            'fonte' => 'Dati non disponibili via API pubblica documentata',
        ],
        'immobiliare' => [
            'prezzo_mq_medio_residenziale' => null,
            'prezzo_mq_medio_commerciale' => null,
            'transazioni_anno' => null,
            'fonte' => 'Dati non disponibili via API pubblica documentata',
        ],
        'fonte_dati' => 'ISTAT SDMX + fallback statici',
        'ultimo_aggiornamento' => date('Y-m-d'),
        'data_source' => $popolazione !== null ? 'istat_sdmx' : 'fallback',
        'notice' => $codiceIstat === null
            ? 'Codice ISTAT del comune non disponibile nel dataset locale.'
            : ($popolazione === null ? 'Demografia non disponibile al momento da ISTAT SDMX.' : null),
    ];
}

function lookupComuneMeta(string $comune, string $provincia): ?array
{
    static $map = null;

    if ($map === null) {
        $map = [];

        $path = __DIR__ . '/../data/comuni_catastali.json';
        if (!is_file($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        $rows = $raw !== false ? json_decode($raw, true) : null;
        if (!is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $nome = normalizeComune((string)($row['nome'] ?? ''));
            $sigla = normalizeProvincia((string)($row['sigla_provincia'] ?? ''));
            if ($nome === '' || $sigla === '') {
                continue;
            }

            $map["{$nome}|{$sigla}"] = [
                'codice_catastale' => strtoupper(trim((string)($row['codice_catastale'] ?? ''))) ?: null,
                'codice_istat' => normalizeIstatCode((string)($row['codice_istat'] ?? '')),
                'regione' => trim((string)($row['regione'] ?? '')) ?: null,
            ];
        }
    }

    return $map["{$comune}|{$provincia}"] ?? null;
}

function fetchPopulationFromISTAT(string $codiceIstat): ?int
{
    $url = 'https://sdmx.istat.it/SDMXWS/rest/data/22_289/DCIS_POPRES1/.' . rawurlencode($codiceIstat) . '?format=sdmxjson';
    $raw = httpGet($url);
    if ($raw === null) {
        return null;
    }

    $json = json_decode($raw, true);
    if (!is_array($json)) {
        return null;
    }

    return parseISTATPopulation($json);
}

function parseISTATPopulation(array $data): ?int
{
    $dataset = $data['dataSets'][0] ?? null;
    if (!is_array($dataset) || !isset($dataset['series']) || !is_array($dataset['series'])) {
        return null;
    }

    $latest = null;
    foreach ($dataset['series'] as $series) {
        if (!is_array($series)) {
            continue;
        }

        $obs = $series['observations'] ?? null;
        if (!is_array($obs)) {
            continue;
        }

        foreach ($obs as $point) {
            if (!is_array($point) || !isset($point[0]) || !is_numeric($point[0])) {
                continue;
            }
            $latest = (int)round((float)$point[0]);
        }
    }

    return $latest;
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
        CURLOPT_TIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_USERAGENT => 'EasyCatasto-Analytics/2.0 (+https://github.com/simonedeitos/analytics)',
    ]);

    $body = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($body === false || $err !== '' || $status >= 400) {
        return null;
    }

    return (string)$body;
}

function normalizeComune(string $value): string
{
    $value = strtoupper(trim($value));
    $value = preg_replace('/[_\-]+/', ' ', $value) ?? '';
    return preg_replace('/\s+/', ' ', $value) ?? '';
}

function normalizeProvincia(string $value): string
{
    $value = strtoupper(trim($value));
    return preg_replace('/[^A-Z]/', '', $value) ?? '';
}

function normalizeIstatCode(string $value): ?string
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if ($digits === '') {
        return null;
    }

    return str_pad(substr($digits, -6), 6, '0', STR_PAD_LEFT);
}

function slugify(string $text): string
{
    $text = strtolower(trim($text));
    $text = preg_replace('/[^a-z0-9]+/', '_', $text) ?? $text;
    return trim($text, '_');
}

function ensureStatsCacheDir(): void
{
    if (!is_dir(STATS_CACHE_DIR)) {
        @mkdir(STATS_CACHE_DIR, 0755, true);
    }
}

function sendJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
