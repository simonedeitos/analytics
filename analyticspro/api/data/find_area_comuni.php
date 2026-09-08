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
    $queryLength = function_exists('mb_strlen') ? (int) mb_strlen($query) : strlen($query);
    if ($queryLength < 3) {
        error_log('[find_area_comuni] query="' . $query . '" results=0 (query too short)');
        analyticspro_json([
            'ok' => true,
            'comuni' => [],
        ]);
    }
    $results = analyticspro_gml_search_comuni($query, $limit);
    error_log('[find_area_comuni] query="' . $query . '" results=' . count($results) . ' limit=' . $limit);
    analyticspro_json([
        'ok' => true,
        'comuni' => $results,
    ]);
} catch (Throwable $exception) {
    error_log('[find_area_comuni] query="' . ($query ?? '') . '" limit=' . ($limit ?? 0) . ' error=' . $exception->getMessage());
    analyticspro_json(['ok' => false, 'error' => $exception->getMessage()], 422);
}
