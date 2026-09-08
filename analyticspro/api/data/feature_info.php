<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/api_bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/cadastral_map.php';

analyticspro_api_guard();
analyticspro_api_require_auth();

function analyticspro_feature_info_request(string $url, string $accept): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'AnalyticsPRO/1.0',
        CURLOPT_HTTPHEADER => [
            'Accept: ' . $accept,
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
        throw new RuntimeException($error !== '' ? $error : 'Risposta non disponibile.');
    }

    return ['status' => $status, 'content_type' => $contentType, 'body' => (string) $body];
}

function analyticspro_feature_info_complete_fields(array $fields, float $lat, float $lng): ?array
{
    $normalized = analyticspro_feature_info_normalize($fields);
    if ($normalized === null) {
        return null;
    }

    return analyticspro_cadastral_complete_fields($normalized, $lat, $lng);
}

function analyticspro_feature_info_lookup(array $source, array $aliases): string
{
    foreach ($aliases as $alias) {
        foreach ($source as $key => $value) {
            if (strcasecmp((string) $key, $alias) !== 0) {
                continue;
            }
            $resolved = trim((string) $value);
            if ($resolved !== '') {
                return $resolved;
            }
        }
    }

    return '';
}

function analyticspro_feature_info_reference_fields(string $reference): array
{
    $reference = trim($reference);
    $fields = [
        'cod_catastale' => '',
        'foglio' => '',
        'particella' => '',
        'subalterno' => '',
    ];
    if ($reference === '') {
        return $fields;
    }

    if (preg_match('/([A-Z][0-9]{3})/i', $reference, $codeMatch)) {
        $fields['cod_catastale'] = strtoupper($codeMatch[1]);
    }
    if (preg_match('/(?:_|\.)(\d{2,6}[A-Z]?\d*)(?:_|\.)(\d+[A-Z]?)((?:_|\.)(\d+[A-Z]?))?/i', $reference, $matches)) {
        $fields['foglio'] = ltrim($matches[1], '0');
        $fields['particella'] = ltrim($matches[2], '0');
        $fields['subalterno'] = isset($matches[4]) ? ltrim($matches[4], '0') : '';
    }

    return $fields;
}

function analyticspro_feature_info_normalize(array $fields): ?array
{
    $base = [
        'comune' => trim((string) ($fields['comune'] ?? '')),
        'provincia' => trim((string) ($fields['provincia'] ?? '')),
        'cod_catastale' => trim((string) ($fields['cod_catastale'] ?? '')),
        'sezione' => trim((string) ($fields['sezione'] ?? '')),
        'foglio' => trim((string) ($fields['foglio'] ?? '')),
        'particella' => trim((string) ($fields['particella'] ?? '')),
        'subalterno' => trim((string) ($fields['subalterno'] ?? '')),
        'categoria' => trim((string) ($fields['categoria'] ?? '')),
        'indirizzo' => trim((string) ($fields['indirizzo'] ?? '')),
        'civico' => trim((string) ($fields['civico'] ?? '')),
    ];

    if (!empty($fields['nationalCadastralReference'])) {
        foreach (analyticspro_feature_info_reference_fields((string) $fields['nationalCadastralReference']) as $key => $value) {
            if ($base[$key] === '' && $value !== '') {
                $base[$key] = $value;
            }
        }
    }

    if ($base['indirizzo'] !== '' && $base['civico'] !== '' && !str_contains($base['indirizzo'], $base['civico'])) {
        $base['indirizzo'] = trim($base['indirizzo'] . ' ' . $base['civico']);
    }

    foreach (['foglio', 'particella', 'subalterno'] as $numericField) {
        if (preg_match('/^\d+$/', $base[$numericField]) === 1) {
            $base[$numericField] = ltrim($base[$numericField], '0');
            if ($base[$numericField] === '') {
                $base[$numericField] = '0';
            }
        }
    }

    if ($base['comune'] === '' && $base['foglio'] === '' && $base['particella'] === '') {
        return null;
    }

    return $base;
}

function analyticspro_feature_info_from_json(string $body): ?array
{
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return null;
    }

    $fields = [
        'comune' => analyticspro_feature_info_lookup($decoded, ['COMUNE', 'comune', 'DESCR_COMUNE']),
        'provincia' => analyticspro_feature_info_lookup($decoded, ['PROVINCIA', 'provincia']),
        'cod_catastale' => analyticspro_feature_info_lookup($decoded, ['COD_COMUNE', 'cod_catastale']),
        'sezione' => analyticspro_feature_info_lookup($decoded, ['SEZIONE', 'sezione']),
        'foglio' => analyticspro_feature_info_lookup($decoded, ['FOGLIO', 'foglio']),
        'particella' => analyticspro_feature_info_lookup($decoded, ['NUM_PART', 'PARTICELLA', 'particella']),
        'subalterno' => analyticspro_feature_info_lookup($decoded, ['SUBALTERNO', 'NUM_SUB', 'subalterno']),
        'categoria' => analyticspro_feature_info_lookup($decoded, ['CATEGORIA', 'categoria']),
        'indirizzo' => analyticspro_feature_info_lookup($decoded, ['INDIRIZZO', 'indirizzo']),
        'civico' => analyticspro_feature_info_lookup($decoded, ['CIVICO', 'civico']),
        'nationalCadastralReference' => analyticspro_feature_info_lookup($decoded, ['nationalCadastralReference']),
    ];

    return analyticspro_feature_info_normalize($fields);
}

function analyticspro_feature_info_capture_pairs(string $text): array
{
    $pairs = [];
    if (preg_match_all('/([A-Za-zÀ-ÿ_ ]+)\s*[:=]\s*([^\n\r;<>]+)/u', $text, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $pairs[trim($match[1])] = trim($match[2]);
        }
    }
    return $pairs;
}

function analyticspro_feature_info_from_text_pairs(array $pairs): ?array
{
    $fields = [
        'comune' => analyticspro_feature_info_lookup($pairs, ['Comune', 'COMUNE']),
        'provincia' => analyticspro_feature_info_lookup($pairs, ['Provincia', 'PROVINCIA']),
        'cod_catastale' => analyticspro_feature_info_lookup($pairs, ['COD_COMUNE', 'Codice comune', 'Codice catastale']),
        'sezione' => analyticspro_feature_info_lookup($pairs, ['Sezione', 'SEZIONE']),
        'foglio' => analyticspro_feature_info_lookup($pairs, ['Foglio', 'FOGLIO']),
        'particella' => analyticspro_feature_info_lookup($pairs, ['Particella', 'NUM_PART', 'PARTICELLA']),
        'subalterno' => analyticspro_feature_info_lookup($pairs, ['Subalterno', 'SUBALTERNO', 'NUM_SUB']),
        'categoria' => analyticspro_feature_info_lookup($pairs, ['Categoria', 'CATEGORIA']),
        'indirizzo' => analyticspro_feature_info_lookup($pairs, ['Indirizzo', 'INDIRIZZO']),
        'civico' => analyticspro_feature_info_lookup($pairs, ['Civico', 'CIVICO']),
        'nationalCadastralReference' => analyticspro_feature_info_lookup($pairs, ['nationalCadastralReference']),
    ];

    return analyticspro_feature_info_normalize($fields);
}

function analyticspro_feature_info_from_html(string $body): ?array
{
    $text = trim(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $body)));
    if ($text === '') {
        return null;
    }
    return analyticspro_feature_info_from_text_pairs(analyticspro_feature_info_capture_pairs($text));
}

function analyticspro_feature_info_from_plain(string $body): ?array
{
    return analyticspro_feature_info_from_text_pairs(analyticspro_feature_info_capture_pairs($body));
}

function analyticspro_feature_info_from_gml(string $body): ?array
{
    $pairs = [];
    if (preg_match_all('/<[^>]*:?([A-Za-z0-9_]+)[^>]*>([^<]+)<\/[^>]+>/u', $body, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $match) {
            $pairs[$match[1]] = trim($match[2]);
        }
    }
    return analyticspro_feature_info_from_text_pairs($pairs);
}

try {
    $lat = (float) ($_GET['lat'] ?? 0);
    $lng = (float) ($_GET['lng'] ?? 0);
    $zoom = (int) ($_GET['zoom'] ?? 0);
    if (!$lat || !$lng) {
        throw new RuntimeException('Coordinate non valide.');
    }

    $ajaxUrl = 'https://wms.cartografia.agenziaentrate.gov.it/inspire/ajax/ajax.php?op=getDatiOggetto&lon='
        . rawurlencode((string) $lng) . '&lat=' . rawurlencode((string) $lat);
    try {
        $ajaxResponse = analyticspro_feature_info_request($ajaxUrl, 'application/json');
        if ($ajaxResponse['status'] < 400) {
            $ajaxFields = analyticspro_feature_info_complete_fields(
                analyticspro_feature_info_from_json($ajaxResponse['body']) ?? [],
                $lat,
                $lng
            );
            if ($ajaxFields !== null) {
                analyticspro_json(['ok' => true, 'found' => true] + $ajaxFields + ['source' => 'ajax']);
            }
        }
        error_log('[feature_info] ajax empty response at lat=' . $lat . ' lng=' . $lng);
    } catch (Throwable) {
        error_log('[feature_info] ajax lookup failed at lat=' . $lat . ' lng=' . $lng);
    }

    $radius = 0.00035;
    $bbox = implode(',', [
        $lng - $radius,
        $lat - $radius,
        $lng + $radius,
        $lat + $radius,
    ]);
    $baseParams = [
        'language' => 'ita',
        'SERVICE' => 'WMS',
        'REQUEST' => 'GetFeatureInfo',
        'VERSION' => '1.1.1',
        'LAYERS' => 'CP.CadastralParcel',
        'QUERY_LAYERS' => 'CP.CadastralParcel',
        'SRS' => 'EPSG:4326',
        'BBOX' => $bbox,
        'WIDTH' => 256,
        'HEIGHT' => 256,
        'X' => 128,
        'Y' => 128,
        'FEATURE_COUNT' => 5,
    ];

    $formats = [
        'text/html' => 'analyticspro_feature_info_from_html',
        'application/vnd.ogc.gml' => 'analyticspro_feature_info_from_gml',
        'text/plain' => 'analyticspro_feature_info_from_plain',
        'application/json' => 'analyticspro_feature_info_from_json',
    ];

    foreach ($formats as $format => $parser) {
        $url = 'https://wms.cartografia.agenziaentrate.gov.it/inspire/wms/ows01.php?' . http_build_query($baseParams + ['INFO_FORMAT' => $format], '', '&', PHP_QUERY_RFC3986);
        try {
            $response = analyticspro_feature_info_request($url, $format . ',*/*;q=0.8');
        } catch (Throwable) {
            error_log('[feature_info] GetFeatureInfo request failed format=' . $format . ' lat=' . $lat . ' lng=' . $lng);
            continue;
        }
        if ($response['status'] >= 400) {
            error_log('[feature_info] GetFeatureInfo status=' . $response['status'] . ' format=' . $format . ' lat=' . $lat . ' lng=' . $lng);
            continue;
        }
        $fields = analyticspro_feature_info_complete_fields($parser($response['body']) ?? [], $lat, $lng);
        if ($fields !== null) {
            analyticspro_json(['ok' => true, 'found' => true] + $fields + ['source' => 'feature_info', 'info_format' => $format, 'zoom' => $zoom]);
        }
    }

    error_log('[feature_info] no cadastral data at lat=' . $lat . ' lng=' . $lng);
    analyticspro_json(['ok' => true, 'found' => false, 'message' => 'Nessun dato catastale disponibile.']);
} catch (Throwable $exception) {
    error_log('[feature_info] error: ' . $exception->getMessage());
    analyticspro_json(['ok' => false, 'error' => $exception->getMessage()], 422);
}
