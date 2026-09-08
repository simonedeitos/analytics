<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/api_bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/cadastral_map.php';

analyticspro_api_guard();
analyticspro_api_require_auth();

const ANALYTICSPRO_FEATURE_INFO_CONNECT_TIMEOUT = 5;
const ANALYTICSPRO_FEATURE_INFO_AJAX_TIMEOUT = 12;
const ANALYTICSPRO_FEATURE_INFO_GETFEATUREINFO_TIMEOUT = 12;

function analyticspro_feature_info_empty_fields(): array
{
    return [
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
}

function analyticspro_feature_info_log_step(string $step, int $durationMs, array $meta = []): void
{
    $parts = ['[feature_info]', 'step=' . $step, 'duration_ms=' . $durationMs];
    foreach ($meta as $key => $value) {
        $parts[] = $key . '=' . str_replace(' ', '_', trim((string) $value));
    }
    error_log(implode(' ', $parts));
}

function analyticspro_feature_info_message_is_timeout(string $message): bool
{
    return stripos($message, 'timed out') !== false || stripos($message, 'timeout') !== false;
}

function analyticspro_feature_info_not_found_payload(bool $resolveLocation, array $diagnostics = []): array
{
    $status = 'no_data';
    $message = $resolveLocation
        ? 'Comune/provincia non rilevati automaticamente.'
        : 'Nessun dato catastale disponibile.';

    $successfulResponses = (int) ($diagnostics['successful_responses'] ?? 0);
    $timeouts = (int) ($diagnostics['timeouts'] ?? 0);
    $errors = (int) ($diagnostics['errors'] ?? 0);

    if ($successfulResponses === 0 && $timeouts > 0) {
        $status = 'upstream_timeout';
        $message = $resolveLocation
            ? 'Timeout durante la ricerca automatica di comune e provincia.'
            : 'Timeout nel recupero dei dati catastali AdE.';
    } elseif ($successfulResponses === 0 && $errors > 0) {
        $status = 'upstream_error';
        $message = $resolveLocation
            ? 'Il servizio esterno non ha restituito comune e provincia.'
            : 'Il servizio catastale AdE non ha restituito dati interrogabili.';
    }

    return [
        'ok' => true,
        'found' => false,
        'status' => $status,
        'message' => $message,
        'resolve_location' => $resolveLocation,
    ];
}

function analyticspro_feature_info_request(string $url, string $accept, array $options = []): array
{
    $startedAt = microtime(true);
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => max(1, (int) ($options['connect_timeout'] ?? ANALYTICSPRO_FEATURE_INFO_CONNECT_TIMEOUT)),
        CURLOPT_TIMEOUT => max(1, (int) ($options['timeout'] ?? ANALYTICSPRO_FEATURE_INFO_AJAX_TIMEOUT)),
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
        $message = $error !== '' ? $error : 'Risposta non disponibile.';
        if (stripos($message, 'timed out') !== false) {
            $message = 'Timeout upstream del servizio catastale AdE.';
        }
        throw new RuntimeException($message);
    }

    return [
        'status' => $status,
        'content_type' => $contentType,
        'body' => (string) $body,
        'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
    ];
}

function analyticspro_feature_info_complete_fields(
    array $fields,
    float $lat,
    float $lng,
    bool $resolveLocation = false,
    ?callable $trace = null
): ?array
{
    $normalized = analyticspro_feature_info_normalize($fields);
    if (!$resolveLocation) {
        return $normalized !== null && analyticspro_cadastral_has_meaningful_fields($normalized) ? $normalized : null;
    }

    $completed = analyticspro_cadastral_complete_fields(
        $normalized ?? analyticspro_feature_info_empty_fields(),
        $lat,
        $lng,
        null,
        $trace
    );
    return analyticspro_cadastral_has_meaningful_fields($completed) ? $completed : null;
}

function analyticspro_feature_info_has_parcel(array $fields): bool
{
    return trim((string) ($fields['foglio'] ?? '')) !== ''
        || trim((string) ($fields['particella'] ?? '')) !== ''
        || trim((string) ($fields['subalterno'] ?? '')) !== ''
        || trim((string) ($fields['sezione'] ?? '')) !== '';
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

    if (!analyticspro_cadastral_has_meaningful_fields($base)) {
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

function analyticspro_feature_info_getfeatureinfo_profiles(float $lat, float $lng, float $radius): array
{
    return [
        [
            'name' => 'wms130_epsg4258',
            'params' => [
                'language' => 'ita',
                'SERVICE' => 'WMS',
                'REQUEST' => 'GetFeatureInfo',
                'VERSION' => '1.3.0',
                'LAYERS' => 'CP.CadastralParcel',
                'QUERY_LAYERS' => 'CP.CadastralParcel',
                'CRS' => 'EPSG:4258',
                'BBOX' => implode(',', [
                    $lat - $radius,
                    $lng - $radius,
                    $lat + $radius,
                    $lng + $radius,
                ]),
                'WIDTH' => 256,
                'HEIGHT' => 256,
                'I' => 128,
                'J' => 128,
                'FEATURE_COUNT' => 5,
            ],
        ],
        [
            'name' => 'wms111_epsg4326',
            'params' => [
                'language' => 'ita',
                'SERVICE' => 'WMS',
                'REQUEST' => 'GetFeatureInfo',
                'VERSION' => '1.1.1',
                'LAYERS' => 'CP.CadastralParcel',
                'QUERY_LAYERS' => 'CP.CadastralParcel',
                'SRS' => 'EPSG:4326',
                'BBOX' => implode(',', [
                    $lng - $radius,
                    $lat - $radius,
                    $lng + $radius,
                    $lat + $radius,
                ]),
                'WIDTH' => 256,
                'HEIGHT' => 256,
                'X' => 128,
                'Y' => 128,
                'FEATURE_COUNT' => 5,
            ],
        ],
    ];
}

try {
    $lat = (float) ($_GET['lat'] ?? 0);
    $lng = (float) ($_GET['lng'] ?? 0);
    $zoom = (int) ($_GET['zoom'] ?? 0);
    $resolveLocation = ((int) ($_GET['resolve_location'] ?? 0)) === 1;
    if (!$lat || !$lng) {
        throw new RuntimeException('Coordinate non valide.');
    }

    $trace = static function (string $step, int $durationMs, array $meta = []) use ($lat, $lng, $resolveLocation): void {
        analyticspro_feature_info_log_step($step, $durationMs, $meta + [
            'lat' => number_format($lat, 6, '.', ''),
            'lng' => number_format($lng, 6, '.', ''),
            'resolve_location' => $resolveLocation ? '1' : '0',
        ]);
    };
    $diagnostics = [
        'successful_responses' => 0,
        'timeouts' => 0,
        'errors' => 0,
    ];

    $ajaxUrl = 'https://wms.cartografia.agenziaentrate.gov.it/inspire/ajax/ajax.php?op=getDatiOggetto&lon='
        . rawurlencode((string) $lng) . '&lat=' . rawurlencode((string) $lat);
    $ajaxStartedAt = microtime(true);
    try {
        $ajaxResponse = analyticspro_feature_info_request($ajaxUrl, 'application/json', [
            'connect_timeout' => ANALYTICSPRO_FEATURE_INFO_CONNECT_TIMEOUT,
            'timeout' => ANALYTICSPRO_FEATURE_INFO_AJAX_TIMEOUT,
        ]);
        $trace('ajax_getDatiOggetto', (int) ($ajaxResponse['duration_ms'] ?? 0), ['status' => $ajaxResponse['status'] ?? 0]);
        if ($ajaxResponse['status'] < 400) {
            $diagnostics['successful_responses']++;
            $ajaxFields = analyticspro_feature_info_complete_fields(
                analyticspro_feature_info_from_json($ajaxResponse['body']) ?? [],
                $lat,
                $lng,
                $resolveLocation,
                $trace
            );
            if ($ajaxFields !== null) {
                analyticspro_json(['ok' => true, 'found' => true] + $ajaxFields + [
                    'source' => 'ajax',
                    'parcel_found' => analyticspro_feature_info_has_parcel($ajaxFields),
                    'resolve_location' => $resolveLocation,
                ]);
            }
        }
        $trace('ajax_no_match', (int) round((microtime(true) - $ajaxStartedAt) * 1000));
    } catch (Throwable $exception) {
        if (analyticspro_feature_info_message_is_timeout($exception->getMessage())) {
            $diagnostics['timeouts']++;
        } else {
            $diagnostics['errors']++;
        }
        $trace('ajax_error', (int) round((microtime(true) - $ajaxStartedAt) * 1000), ['error' => $exception->getMessage()]);
    }

    $radius = 0.00035;
    $requestProfiles = analyticspro_feature_info_getfeatureinfo_profiles($lat, $lng, $radius);

    $formats = [
        'text/html' => 'analyticspro_feature_info_from_html',
        'application/vnd.ogc.gml' => 'analyticspro_feature_info_from_gml',
        'text/plain' => 'analyticspro_feature_info_from_plain',
    ];

    foreach ($requestProfiles as $profile) {
        foreach ($formats as $format => $parser) {
            $url = 'https://wms.cartografia.agenziaentrate.gov.it/inspire/wms/ows01.php?' . http_build_query($profile['params'] + ['INFO_FORMAT' => $format], '', '&', PHP_QUERY_RFC3986);
            $requestStartedAt = microtime(true);
            try {
                $response = analyticspro_feature_info_request($url, $format . ',*/*;q=0.8', [
                    'connect_timeout' => ANALYTICSPRO_FEATURE_INFO_CONNECT_TIMEOUT,
                    'timeout' => ANALYTICSPRO_FEATURE_INFO_GETFEATUREINFO_TIMEOUT,
                ]);
                $trace('getfeatureinfo_request', (int) ($response['duration_ms'] ?? 0), [
                    'profile' => $profile['name'] ?? '',
                    'format' => $format,
                    'status' => $response['status'] ?? 0,
                ]);
            } catch (Throwable $exception) {
                $timedOut = analyticspro_feature_info_message_is_timeout($exception->getMessage());
                if ($timedOut) {
                    $diagnostics['timeouts']++;
                } else {
                    $diagnostics['errors']++;
                }
                $trace('getfeatureinfo_error', (int) round((microtime(true) - $requestStartedAt) * 1000), [
                    'profile' => $profile['name'] ?? '',
                    'format' => $format,
                    'error' => $exception->getMessage(),
                ]);
                if ($timedOut) {
                    break 2;
                }
                continue;
            }
            if ($response['status'] >= 400) {
                continue;
            }
            $diagnostics['successful_responses']++;
            $fields = analyticspro_feature_info_complete_fields(
                $parser($response['body']) ?? [],
                $lat,
                $lng,
                $resolveLocation,
                $trace
            );
            if ($fields !== null) {
                analyticspro_json(['ok' => true, 'found' => true] + $fields + [
                    'source' => 'feature_info',
                    'request_profile' => $profile['name'] ?? '',
                    'info_format' => $format,
                    'zoom' => $zoom,
                    'parcel_found' => analyticspro_feature_info_has_parcel($fields),
                    'resolve_location' => $resolveLocation,
                ]);
            }
        }
    }

    if ($resolveLocation) {
        $resolveOnlyStartedAt = microtime(true);
        $reverseFields = analyticspro_feature_info_complete_fields([], $lat, $lng, true, $trace);
        $trace('resolve_location_only', (int) round((microtime(true) - $resolveOnlyStartedAt) * 1000), [
            'found' => $reverseFields !== null ? '1' : '0',
        ]);
        if ($reverseFields !== null) {
            analyticspro_json(['ok' => true, 'found' => true] + $reverseFields + [
                'source' => 'resolve_location',
                'zoom' => $zoom,
                'parcel_found' => analyticspro_feature_info_has_parcel($reverseFields),
                'resolve_location' => true,
            ]);
        }
    }

    $trace('no_match', 0);
    analyticspro_json(analyticspro_feature_info_not_found_payload($resolveLocation, $diagnostics));
} catch (Throwable $exception) {
    error_log('[feature_info] error: ' . $exception->getMessage());
    analyticspro_json(['ok' => false, 'error' => $exception->getMessage()], 422);
}
