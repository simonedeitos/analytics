<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/api_bootstrap.php';

analyticspro_api_guard();
analyticspro_api_require_auth();

$baseUrl = 'https://wms.cartografia.agenziaentrate.gov.it/inspire/wms/ows01.php';
$parsedBase = parse_url($baseUrl);
if (($parsedBase['host'] ?? '') !== 'wms.cartografia.agenziaentrate.gov.it') {
    http_response_code(500);
    exit('Host proxy non valido.');
}

try {
    $queryParams = $_GET;
    unset($queryParams['url']);
    if (!isset($queryParams['language']) || trim((string) $queryParams['language']) === '') {
        $queryParams['language'] = 'ita';
    }

    $targetUrl = $baseUrl . '?' . http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);
    $parsedTarget = parse_url($targetUrl);
    if (($parsedTarget['host'] ?? '') !== 'wms.cartografia.agenziaentrate.gov.it') {
        throw new RuntimeException('Host WMS non consentito.');
    }
    if (empty($queryParams['REQUEST']) && empty($queryParams['request'])) {
        error_log('[wms_proxy] richiesta senza REQUEST: ' . $targetUrl);
    }

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
