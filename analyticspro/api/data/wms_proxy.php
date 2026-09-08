<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/api_bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/cadastral_map.php';

analyticspro_api_guard();
analyticspro_api_require_auth();

try {
    $targetUrl = analyticspro_cadastral_wms_build_target_url(
        isset($_GET['url']) ? (string) $_GET['url'] : null,
        $_GET
    );
    $cachedResponse = analyticspro_cadastral_wms_read_cache($targetUrl, 300);
    if ($cachedResponse !== null) {
        header('Cache-Control: private, max-age=300');
        header('X-Analyticspro-Wms-Proxy: 1');
        header('X-Analyticspro-Wms-Cache: HIT');
        header('Content-Type: ' . $cachedResponse['content_type']);
        header('Content-Length: ' . strlen((string) $cachedResponse['body']));
        echo $cachedResponse['body'];
        exit;
    }

    $response = analyticspro_cadastral_wms_fetch($targetUrl);
    $status = (int) ($response['status'] ?? 0);
    $contentType = (string) ($response['content_type'] ?? '');
    $body = (string) ($response['body'] ?? '');
    if ($status >= 400) {
        error_log('[wms_proxy] upstream status ' . $status . ' url=' . $targetUrl . ' body=' . substr($body, 0, 400));
        http_response_code($status);
        header('Content-Type: ' . ($contentType !== '' ? $contentType : 'text/plain; charset=utf-8'));
        echo $body;
        exit;
    }

    analyticspro_cadastral_wms_write_cache($targetUrl, $response);
    header('Cache-Control: private, max-age=300');
    header('X-Analyticspro-Wms-Proxy: 1');
    header('X-Analyticspro-Wms-Cache: MISS');
    header('Content-Type: ' . ($contentType !== '' ? $contentType : 'image/png'));
    header('Content-Length: ' . strlen($body));
    echo $body;
} catch (Throwable $exception) {
    error_log('[wms_proxy] error: ' . $exception->getMessage());
    http_response_code(502);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE);
}
