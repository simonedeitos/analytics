<?php

declare(strict_types=1);

// Uso: php analyticspro/cron/gml_index_worker.php <job_id>
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require dirname(__DIR__) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/gml_catalog.php';
require_once ANALYTICSPRO_ROOT . '/includes/gml_index_worker_logic.php';

$jobId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($jobId <= 0) {
    fwrite(STDERR, "Uso: php gml_index_worker.php <job_id>\n");
    exit(1);
}
session_write_close();

try {
    do {
        $result = analyticspro_gml_index_step(analyticspro_db(), $jobId, INF);
        if (!empty($result['busy'])) {
            usleep(500000);
        }
    } while (!$result['done']);
    exit($result['job']['status'] === 'failed' ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, '[gml_index_worker] ' . $e->getMessage() . "\n");
    exit(1);
}
