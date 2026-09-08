<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/api_bootstrap.php';

analyticspro_api_guard();
analyticspro_api_require_auth();

$defaultBaseUrl = 'https://wms.cartografia.agenziaentrate.gov.it/inspire/wms/ows01.php?language=ita';
$allowedHost = 'wms.cartografia.agenziaentrate.gov.it';

try {
    $baseUrl = trim((string) ($_GET['url'] ?? $defaultBaseUrl));
    if ($baseUrl === '') {
        throw new RuntimeException('Parametro url mancante.');
    }

    $parsedBase = parse_url($baseUrl);
    if (($parsedBase['scheme'] ?? '') !== 'https' || ($parsedBase['host'] ?? '') !== $allowedHost) {
        throw new RuntimeException('Host WMS non consentito.');
    }

    $baseQuery = [];
    parse_str((string) ($parsedBase['query'] ?? ''), $baseQuery);

    $queryParams = $_GET;
    unset($queryParams['url']);
    $forwardParams = array_merge($baseQuery, $queryParams);
    if (!isset($forwardParams['language']) || trim((string) $forwardParams['language']) === '') {
        $forwardParams['language'] = 'ita';
    }
    $basePath = ($parsedBase['path'] ?? '') !== '' ? (string) $parsedBase['path'] : '/inspire/wms/ows01.php';
    $targetUrl = 'https://' . $allowedHost . $basePath . '?' . http_build_query($forwardParams, '', '&', PHP_QUERY_RFC3986);

    $ch = curl_init($targetUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'AnalyticsPRO/1.0',
        CURLOPT_HTTPHEADER => [
            'Accept: image/png,image/*;q=0.9,*/*;q=0.1',
            'Referer: https://wms.cartografia.agenziaentrate.gov.it/',
        ],
    ]);
    $body = curl_exec($ch);
    if ($body === false) {
        throw new RuntimeException('Richiesta WMS non riuscita: ' . curl_error($ch));
    }
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $contentType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($status >= 400) {
        error_log('[wms_proxy] upstream status ' . $status . ' url=' . $targetUrl . ' body=' . substr($body, 0, 400));
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');
        echo $body;
        exit;
    }

    header('Cache-Control: private, max-age=300');
    header('X-Analyticspro-Wms-Proxy: 1');
    header('Content-Type: ' . ($contentType !== '' ? $contentType : 'image/png'));
    echo $body;
} catch (Throwable $exception) {
    error_log('[wms_proxy] error: ' . $exception->getMessage());
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}
