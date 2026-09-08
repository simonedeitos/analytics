<?php

declare(strict_types=1);

require_once __DIR__ . '/gml_catalog.php';
require_once __DIR__ . '/wfs_lookup.php';

/**
 * Shared cadastral map helpers ported/adapted from the working CataMap flow:
 * WMS proxy URL validation/cache and map-click comune/provincia normalization.
 */

function analyticspro_cadastral_default_wms_base_url(): string
{
    return 'https://wms.cartografia.agenziaentrate.gov.it/inspire/wms/ows01.php?language=ita';
}

function analyticspro_cadastral_allowed_wms_host(): string
{
    return 'wms.cartografia.agenziaentrate.gov.it';
}

function analyticspro_cadastral_normalize_label(string $value): string
{
    $value = strtoupper(trim($value));
    $value = strtr($value, [
        'À' => 'A',
        'Á' => 'A',
        'Â' => 'A',
        'Ä' => 'A',
        'È' => 'E',
        'É' => 'E',
        'Ê' => 'E',
        'Ë' => 'E',
        'Ì' => 'I',
        'Í' => 'I',
        'Î' => 'I',
        'Ï' => 'I',
        'Ò' => 'O',
        'Ó' => 'O',
        'Ô' => 'O',
        'Ö' => 'O',
        'Ù' => 'U',
        'Ú' => 'U',
        'Û' => 'U',
        'Ü' => 'U',
    ]);
    $value = str_replace(["'", '’', '`'], '', $value);
    $value = str_replace(['_', '-'], ' ', $value);
    $value = preg_replace('/[^A-Z0-9 ]+/u', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
}

function analyticspro_cadastral_province_aliases(): array
{
    static $aliases = null;
    if (is_array($aliases)) {
        return $aliases;
    }

    $base = [
        'AGRIGENTO' => 'AG',
        'ALESSANDRIA' => 'AL',
        'ANCONA' => 'AN',
        'AOSTA' => 'AO',
        'AREZZO' => 'AR',
        'ASCOLI PICENO' => 'AP',
        'ASTI' => 'AT',
        'AVELLINO' => 'AV',
        'BARI' => 'BA',
        'BARLETTA ANDRIA TRANI' => 'BT',
        'BELLUNO' => 'BL',
        'BENEVENTO' => 'BN',
        'BERGAMO' => 'BG',
        'BIELLA' => 'BI',
        'BOLOGNA' => 'BO',
        'BOLZANO' => 'BZ',
        'BRESCIA' => 'BS',
        'BRINDISI' => 'BR',
        'CAGLIARI' => 'CA',
        'CALTANISSETTA' => 'CL',
        'CAMPOBASSO' => 'CB',
        'CASERTA' => 'CE',
        'CATANIA' => 'CT',
        'CATANZARO' => 'CZ',
        'CHIETI' => 'CH',
        'COMO' => 'CO',
        'COSENZA' => 'CS',
        'CREMONA' => 'CR',
        'CROTONE' => 'KR',
        'CUNEO' => 'CN',
        'ENNA' => 'EN',
        'FERMO' => 'FM',
        'FERRARA' => 'FE',
        'FIRENZE' => 'FI',
        'FOGGIA' => 'FG',
        'FORLI CESENA' => 'FC',
        'FROSINONE' => 'FR',
        'GENOVA' => 'GE',
        'GORIZIA' => 'GO',
        'GROSSETO' => 'GR',
        'IMPERIA' => 'IM',
        'ISERNIA' => 'IS',
        'LA SPEZIA' => 'SP',
        'LAQUILA' => 'AQ',
        'LATINA' => 'LT',
        'LECCE' => 'LE',
        'LECCO' => 'LC',
        'LIVORNO' => 'LI',
        'LODI' => 'LO',
        'LUCCA' => 'LU',
        'MACERATA' => 'MC',
        'MANTOVA' => 'MN',
        'MASSA CARRARA' => 'MS',
        'MATERA' => 'MT',
        'MESSINA' => 'ME',
        'MILANO' => 'MI',
        'MODENA' => 'MO',
        'MONZA E BRIANZA' => 'MB',
        'NAPOLI' => 'NA',
        'NOVARA' => 'NO',
        'NUORO' => 'NU',
        'ORISTANO' => 'OR',
        'PADOVA' => 'PD',
        'PALERMO' => 'PA',
        'PARMA' => 'PR',
        'PAVIA' => 'PV',
        'PERUGIA' => 'PG',
        'PESARO E URBINO' => 'PU',
        'PESCARA' => 'PE',
        'PIACENZA' => 'PC',
        'PISA' => 'PI',
        'PISTOIA' => 'PT',
        'PORDENONE' => 'PN',
        'POTENZA' => 'PZ',
        'PRATO' => 'PO',
        'RAGUSA' => 'RG',
        'RAVENNA' => 'RA',
        'REGGIO CALABRIA' => 'RC',
        'REGGIO EMILIA' => 'RE',
        'RIETI' => 'RI',
        'RIMINI' => 'RN',
        'ROMA' => 'RM',
        'ROVIGO' => 'RO',
        'SALERNO' => 'SA',
        'SASSARI' => 'SS',
        'SAVONA' => 'SV',
        'SIENA' => 'SI',
        'SIRACUSA' => 'SR',
        'SONDRIO' => 'SO',
        'SUD SARDEGNA' => 'SU',
        'TARANTO' => 'TA',
        'TERAMO' => 'TE',
        'TERNI' => 'TR',
        'TORINO' => 'TO',
        'TRAPANI' => 'TP',
        'TRENTO' => 'TN',
        'TREVISO' => 'TV',
        'TRIESTE' => 'TS',
        'UDINE' => 'UD',
        'VARESE' => 'VA',
        'VENEZIA' => 'VE',
        'VERBANO CUSIO OSSOLA' => 'VB',
        'VERCELLI' => 'VC',
        'VERONA' => 'VR',
        'VIBO VALENTIA' => 'VV',
        'VICENZA' => 'VI',
        'VITERBO' => 'VT',
        'CITTA METROPOLITANA DI ROMA CAPITALE' => 'RM',
        'CITTA METROPOLITANA DI MILANO' => 'MI',
        'CITTA METROPOLITANA DI NAPOLI' => 'NA',
        'CITTA METROPOLITANA DI FIRENZE' => 'FI',
        'CITTA METROPOLITANA DI BARI' => 'BA',
        'CITTA METROPOLITANA DI BOLOGNA' => 'BO',
        'CITTA METROPOLITANA DI CAGLIARI' => 'CA',
        'CITTA METROPOLITANA DI CATANIA' => 'CT',
        'CITTA METROPOLITANA DI GENOVA' => 'GE',
        'CITTA METROPOLITANA DI MESSINA' => 'ME',
        'CITTA METROPOLITANA DI PALERMO' => 'PA',
        'CITTA METROPOLITANA DI REGGIO CALABRIA' => 'RC',
        'CITTA METROPOLITANA DI TORINO' => 'TO',
        'CITTA METROPOLITANA DI VENEZIA' => 'VE',
    ];

    $aliases = [];
    foreach ($base as $name => $sigla) {
        $normalized = analyticspro_cadastral_normalize_label($name);
        $aliases[$normalized] = $sigla;
        $aliases[preg_replace('/[^A-Z]/', '', $normalized) ?? $normalized] = $sigla;
    }

    return $aliases;
}

function analyticspro_cadastral_normalize_provincia(string $provincia): string
{
    $normalized = analyticspro_cadastral_normalize_label($provincia);
    if ($normalized === '') {
        return '';
    }

    $normalized = preg_replace('/^PROVINCIA DI /', '', $normalized) ?? $normalized;
    $normalized = preg_replace('/^PROVINCIA DEL /', '', $normalized) ?? $normalized;
    $compact = preg_replace('/[^A-Z]/', '', $normalized) ?? '';
    if (strlen($compact) === 2) {
        return $compact;
    }

    $aliases = analyticspro_cadastral_province_aliases();
    return $aliases[$normalized] ?? ($aliases[$compact] ?? '');
}

function analyticspro_cadastral_comuni_candidates(): array
{
    return [
        ANALYTICSPRO_ROOT . '/../data/comuni_catastali.json',
        dirname(ANALYTICSPRO_ROOT) . '/data/comuni_catastali.json',
        __DIR__ . '/../../data/comuni_catastali.json',
        __DIR__ . '/../../../data/comuni_catastali.json',
    ];
}

function analyticspro_cadastral_load_comuni_index(): array
{
    static $index = null;
    if (is_array($index)) {
        return $index;
    }

    $index = [
        'by_code' => [],
        'by_pair' => [],
        'by_comune' => [],
    ];

    foreach (analyticspro_cadastral_comuni_candidates() as $candidate) {
        if (!is_file($candidate)) {
            continue;
        }
        $decoded = json_decode((string) file_get_contents($candidate), true);
        if (!is_array($decoded)) {
            continue;
        }
        foreach ($decoded as $row) {
            if (!is_array($row)) {
                continue;
            }
            $comune = trim((string) ($row['nome'] ?? ''));
            $provincia = analyticspro_cadastral_normalize_provincia((string) ($row['sigla_provincia'] ?? ''));
            $codice = strtoupper(trim((string) ($row['codice_catastale'] ?? '')));
            $comuneKey = analyticspro_gml_norm_nome_comune($comune);
            if ($comune === '' || $provincia === '' || $codice === '' || $comuneKey === '') {
                continue;
            }
            $record = [
                'comune' => $comune,
                'provincia' => $provincia,
                'codice_catastale' => $codice,
            ];
            $index['by_code'][$codice] = $record;
            $index['by_pair'][$comuneKey . '|' . $provincia] = $record;
            $index['by_comune'][$comuneKey][] = $record;
        }
        break;
    }

    return $index;
}

function analyticspro_cadastral_find_location_by_code(string $codCatastale): ?array
{
    $code = strtoupper(trim($codCatastale));
    if ($code === '') {
        return null;
    }

    $index = analyticspro_cadastral_load_comuni_index();
    if (isset($index['by_code'][$code])) {
        return $index['by_code'][$code];
    }

    try {
        $stmt = analyticspro_db()->prepare('SELECT nome_comune, provincia_sigla FROM cadastral_comuni WHERE cod_catastale = :cod LIMIT 1');
        $stmt->execute(['cod' => $code]);
        $row = $stmt->fetch();
        if ($row !== false) {
            return [
                'comune' => trim((string) ($row['nome_comune'] ?? '')),
                'provincia' => analyticspro_cadastral_normalize_provincia((string) ($row['provincia_sigla'] ?? '')),
                'codice_catastale' => $code,
            ];
        }
    } catch (Throwable) {
    }

    return null;
}

function analyticspro_cadastral_find_location_by_comune(string $comune, string $provincia = ''): ?array
{
    $comuneKey = analyticspro_gml_norm_nome_comune($comune);
    if ($comuneKey === '') {
        return null;
    }

    $index = analyticspro_cadastral_load_comuni_index();
    $provinciaSigla = analyticspro_cadastral_normalize_provincia($provincia);
    if ($provinciaSigla !== '' && isset($index['by_pair'][$comuneKey . '|' . $provinciaSigla])) {
        return $index['by_pair'][$comuneKey . '|' . $provinciaSigla];
    }

    $matches = $index['by_comune'][$comuneKey] ?? [];
    if (count($matches) === 1) {
        return $matches[0];
    }

    return null;
}

function analyticspro_cadastral_extract_reverse_location(array $payload): ?array
{
    $address = is_array($payload['address'] ?? null) ? $payload['address'] : [];
    if ($address === []) {
        return null;
    }

    $comune = '';
    foreach (['city', 'town', 'village', 'municipality', 'hamlet'] as $key) {
        $candidate = trim((string) ($address[$key] ?? ''));
        if ($candidate !== '') {
            $comune = $candidate;
            break;
        }
    }
    $provincia = trim((string) ($address['province'] ?? $address['state_district'] ?? $address['county'] ?? ''));
    if ($comune === '' && $provincia === '') {
        return null;
    }

    return [
        'comune' => $comune,
        'provincia' => $provincia,
    ];
}

function analyticspro_cadastral_has_meaningful_fields(array $fields): bool
{
    foreach (['comune', 'provincia', 'cod_catastale', 'foglio', 'particella', 'sezione', 'subalterno', 'indirizzo'] as $key) {
        if (trim((string) ($fields[$key] ?? '')) !== '') {
            return true;
        }
    }

    return false;
}

function analyticspro_cadastral_reverse_geocode_cache_dir(): string
{
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'analyticspro-reverse-geocode-cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function analyticspro_cadastral_reverse_geocode_cache_path(float $lat, float $lng): string
{
    $key = number_format($lat, 6, '.', '') . ',' . number_format($lng, 6, '.', '');
    return analyticspro_cadastral_reverse_geocode_cache_dir() . DIRECTORY_SEPARATOR . sha1($key) . '.json';
}

function analyticspro_cadastral_reverse_geocode_read_cache(float $lat, float $lng, int $maxAge = 604800): ?array
{
    $cachePath = analyticspro_cadastral_reverse_geocode_cache_path($lat, $lng);
    if (!is_file($cachePath)) {
        return null;
    }

    $decoded = json_decode((string) @file_get_contents($cachePath), true);
    if (!is_array($decoded) || (($decoded['cached_at'] ?? 0) + $maxAge) < time()) {
        return null;
    }

    $payload = is_array($decoded['payload'] ?? null) ? $decoded['payload'] : null;
    return is_array($payload) ? analyticspro_cadastral_extract_reverse_location(['address' => $payload]) : null;
}

function analyticspro_cadastral_reverse_geocode_write_cache(float $lat, float $lng, array $payload): void
{
    $address = is_array($payload['address'] ?? null) ? $payload['address'] : [];
    if ($address === []) {
        return;
    }

    $encoded = json_encode([
        'cached_at' => time(),
        'payload' => $address,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (!is_string($encoded) || $encoded === '') {
        return;
    }

    $tmpFile = tempnam(analyticspro_cadastral_reverse_geocode_cache_dir(), 'rev');
    if ($tmpFile === false) {
        return;
    }
    if (@file_put_contents($tmpFile, $encoded, LOCK_EX) === false) {
        @unlink($tmpFile);
        return;
    }
    @chmod($tmpFile, 0664);
    @rename($tmpFile, analyticspro_cadastral_reverse_geocode_cache_path($lat, $lng));
}

function analyticspro_cadastral_reverse_geocode(float $lat, float $lng): ?array
{
    if (!$lat || !$lng) {
        return null;
    }

    $cached = analyticspro_cadastral_reverse_geocode_read_cache($lat, $lng);
    if ($cached !== null) {
        return $cached;
    }

    $url = 'https://nominatim.openstreetmap.org/reverse?format=jsonv2&addressdetails=1&zoom=18'
        . '&lat=' . rawurlencode((string) $lat)
        . '&lon=' . rawurlencode((string) $lng);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'AnalyticsPRO/1.0 (reverse geocoder)',
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Accept-Language: it-IT,it;q=0.9',
        ],
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($body === false || $error !== '' || $status >= 400) {
        return null;
    }

    $decoded = json_decode((string) $body, true);
    if (!is_array($decoded)) {
        return null;
    }

    analyticspro_cadastral_reverse_geocode_write_cache($lat, $lng, $decoded);
    return analyticspro_cadastral_extract_reverse_location($decoded);
}

function analyticspro_cadastral_complete_fields(array $fields, float $lat = 0.0, float $lng = 0.0, ?callable $reverseGeocoder = null): array
{
    $completed = $fields + [
        'comune' => '',
        'provincia' => '',
        'cod_catastale' => '',
        'sezione' => '',
        'foglio' => '',
        'particella' => '',
        'subalterno' => '',
        'categoria' => '',
        'indirizzo' => '',
        'civico' => '',
    ];
    $completed['comune'] = trim((string) ($completed['comune'] ?? ''));
    $completed['provincia'] = trim((string) ($completed['provincia'] ?? ''));
    $completed['cod_catastale'] = strtoupper(trim((string) ($completed['cod_catastale'] ?? '')));

    $locationByCode = analyticspro_cadastral_find_location_by_code($completed['cod_catastale']);
    if ($locationByCode !== null) {
        if ($completed['comune'] === '') {
            $completed['comune'] = $locationByCode['comune'];
        }
        if ($completed['provincia'] === '') {
            $completed['provincia'] = $locationByCode['provincia'];
        }
    }

    $locationByComune = analyticspro_cadastral_find_location_by_comune($completed['comune'], $completed['provincia']);
    if ($locationByComune === null && ($completed['comune'] === '' || $completed['provincia'] === '') && $lat && $lng) {
        $reverseLocation = $reverseGeocoder !== null ? $reverseGeocoder($lat, $lng) : analyticspro_cadastral_reverse_geocode($lat, $lng);
        if (is_array($reverseLocation)) {
            if ($completed['comune'] === '') {
                $completed['comune'] = trim((string) ($reverseLocation['comune'] ?? ''));
            }
            if ($completed['provincia'] === '') {
                $completed['provincia'] = trim((string) ($reverseLocation['provincia'] ?? ''));
            }
            $locationByComune = analyticspro_cadastral_find_location_by_comune($completed['comune'], $completed['provincia']);
        }
    }

    if ($locationByComune !== null) {
        $completed['comune'] = $locationByComune['comune'];
        $completed['provincia'] = $locationByComune['provincia'];
        if ($completed['cod_catastale'] === '') {
            $completed['cod_catastale'] = $locationByComune['codice_catastale'];
        }
    }

    if ($completed['cod_catastale'] === '' && $completed['comune'] !== '' && $completed['provincia'] !== '') {
        $resolved = analyticspro_resolve_cod_catastale('', $completed['comune'], $completed['provincia']);
        if (!empty($resolved['cod']) && is_string($resolved['cod'])) {
            $completed['cod_catastale'] = strtoupper($resolved['cod']);
        }
    }

    $locationByCode = analyticspro_cadastral_find_location_by_code($completed['cod_catastale']);
    if ($locationByCode !== null) {
        if ($completed['comune'] === '') {
            $completed['comune'] = $locationByCode['comune'];
        }
        if ($completed['provincia'] === '') {
            $completed['provincia'] = $locationByCode['provincia'];
        }
    }

    $completed['provincia'] = analyticspro_cadastral_normalize_provincia($completed['provincia']);

    return $completed;
}

function analyticspro_cadastral_wms_build_target_url(?string $baseUrl, array $queryParams): string
{
    return analyticspro_cadastral_wms_build_target_url_with_raw_query($baseUrl, $queryParams, null);
}

function analyticspro_cadastral_wms_build_target_url_with_raw_query(?string $baseUrl, array $queryParams, ?string $rawQueryString): string
{
    $resolvedBaseUrl = trim((string) ($baseUrl ?? analyticspro_cadastral_default_wms_base_url()));
    if ($resolvedBaseUrl === '') {
        throw new RuntimeException('Parametro url mancante.');
    }

    $parsedBase = parse_url($resolvedBaseUrl);
    $allowedHost = analyticspro_cadastral_allowed_wms_host();
    if (($parsedBase['scheme'] ?? '') !== 'https' || ($parsedBase['host'] ?? '') !== $allowedHost) {
        throw new RuntimeException('Host WMS non consentito.');
    }

    $basePath = ($parsedBase['path'] ?? '') !== '' ? (string) $parsedBase['path'] : '/inspire/wms/ows01.php';
    $queryParts = [];
    $baseQueryString = trim((string) ($parsedBase['query'] ?? ''));
    $hasLanguage = str_contains(strtolower($baseQueryString), 'language=');
    if ($baseQueryString !== '') {
        $queryParts[] = $baseQueryString;
    }

    if ($rawQueryString !== null && trim($rawQueryString) !== '') {
        foreach (explode('&', $rawQueryString) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $key = urldecode((string) strtok($part, '='));
            if (strcasecmp($key, 'url') === 0) {
                continue;
            }
            if (strcasecmp($key, 'language') === 0) {
                $hasLanguage = true;
            }
            $queryParts[] = $part;
        }
    } else {
        unset($queryParams['url']);
        if (!isset($queryParams['language']) || trim((string) $queryParams['language']) === '') {
            $queryParams['language'] = 'ita';
        }
        if (isset($queryParams['language']) && trim((string) $queryParams['language']) !== '') {
            $hasLanguage = true;
        }
        $forwardQuery = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
        if ($forwardQuery !== '') {
            $queryParts[] = $forwardQuery;
        }
    }

    if (!$hasLanguage) {
        $queryParts[] = 'language=ita';
    }

    return 'https://' . $allowedHost . $basePath . '?' . implode('&', $queryParts);
}

function analyticspro_cadastral_wms_cache_dir(): string
{
    $dir = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'analyticspro-wms-cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}

function analyticspro_cadastral_wms_cache_path(string $targetUrl): string
{
    return analyticspro_cadastral_wms_cache_dir() . DIRECTORY_SEPARATOR . sha1($targetUrl) . '.json';
}

function analyticspro_cadastral_wms_safe_content_type(string $contentType): ?string
{
    $mime = strtolower(trim(strtok($contentType, ';') ?: ''));
    $allowed = [
        'image/png',
        'image/jpeg',
        'image/gif',
        'image/webp',
    ];
    return in_array($mime, $allowed, true) ? $mime : null;
}

function analyticspro_cadastral_wms_read_cache(string $targetUrl, int $maxAge = 300): ?array
{
    $cachePath = analyticspro_cadastral_wms_cache_path($targetUrl);
    if (!is_file($cachePath)) {
        return null;
    }

    $decoded = json_decode((string) @file_get_contents($cachePath), true);
    if (!is_array($decoded) || empty($decoded['cached_at']) || empty($decoded['content_type']) || empty($decoded['body_base64'])) {
        return null;
    }
    if (((int) $decoded['cached_at'] + $maxAge) < time()) {
        return null;
    }

    $body = base64_decode((string) $decoded['body_base64'], true);
    $contentType = analyticspro_cadastral_wms_safe_content_type((string) $decoded['content_type']);
    if ($body === false || $body === '' || $contentType === null) {
        return null;
    }

    return [
        'status' => 200,
        'content_type' => $contentType,
        'body' => $body,
        'cached_at' => (int) $decoded['cached_at'],
    ];
}

function analyticspro_cadastral_wms_write_cache(string $targetUrl, array $response): void
{
    $body = (string) ($response['body'] ?? '');
    $contentType = analyticspro_cadastral_wms_safe_content_type((string) ($response['content_type'] ?? ''));
    if ($body === '' || $contentType === null) {
        return;
    }

    $payload = json_encode([
        'cached_at' => time(),
        'content_type' => $contentType,
        'body_base64' => base64_encode($body),
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload) || $payload === '') {
        return;
    }

    $tmpFile = tempnam(analyticspro_cadastral_wms_cache_dir(), 'wms');
    if ($tmpFile === false) {
        return;
    }
    if (@file_put_contents($tmpFile, $payload, LOCK_EX) === false) {
        @unlink($tmpFile);
        return;
    }
    @chmod($tmpFile, 0664);
    @rename($tmpFile, analyticspro_cadastral_wms_cache_path($targetUrl));
}

function analyticspro_cadastral_wms_fetch(string $targetUrl): array
{
    $ch = curl_init($targetUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'AnalyticsPRO/1.0',
        CURLOPT_HTTPHEADER => [
            'Accept: image/png,image/*;q=0.9,*/*;q=0.1',
            'Accept-Language: it-IT,it;q=0.9',
            'Referer: https://wms.cartografia.agenziaentrate.gov.it/',
        ],
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($body === false || $error !== '') {
        throw new RuntimeException('Richiesta WMS non riuscita: ' . ($error !== '' ? $error : 'risposta non disponibile'));
    }

    return [
        'status' => $status,
        'content_type' => $contentType,
        'body' => (string) $body,
    ];
}
