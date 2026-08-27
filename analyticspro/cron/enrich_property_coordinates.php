<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

$batchId = isset($argv[1]) ? (int) $argv[1] : 0;

analyticspro_enrich_batch_coordinates($batchId);
