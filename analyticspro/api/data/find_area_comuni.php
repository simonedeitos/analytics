<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/api_bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/gml_catalog.php';

analyticspro_api_guard();
analyticspro_api_require_auth();

try {
    $query = trim((string) ($_POST['q'] ?? analyticspro_get('q', '')));
    $limit = (int) ($_POST['limit'] ?? analyticspro_get('limit', 12));
    $limit = max(1, min(30, $limit));
    if (mb_strlen($query) < 3) {
        analyticspro_json([
            'ok' => true,
            'comuni' => [],
        ]);
    }
    analyticspro_json([
        'ok' => true,
        'comuni' => analyticspro_gml_search_comuni($query, $limit),
    ]);
} catch (Throwable $exception) {
    error_log('[find_area_comuni] query="' . ($query ?? '') . '" limit=' . ($limit ?? 0) . ' error=' . $exception->getMessage());
    analyticspro_json(['ok' => false, 'error' => $exception->getMessage()], 422);
}
