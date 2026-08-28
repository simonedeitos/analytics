<?php

declare(strict_types=1);

/**
 * Test: catalogo GML auto-invalidante su aggiunta file senza force.
 *
 * Exit code: 0 = pass, 1 = fail.
 */

if (!defined('ANALYTICSPRO_ROOT')) {
    define('ANALYTICSPRO_ROOT', dirname(__DIR__));
}
if (!function_exists('analyticspro_db')) {
    function analyticspro_db(): never
    {
        throw new RuntimeException('analyticspro_db() non disponibile in modalità test');
    }
}

require_once dirname(__DIR__) . '/includes/gml_stream_parser.php';
require_once dirname(__DIR__) . '/includes/geo_centroid.php';
require_once dirname(__DIR__) . '/includes/gml_catalog.php';

$gmlDir = analyticspro_gml_dir();
$indexDir = analyticspro_gml_index_dir();
@mkdir($gmlDir, 0775, true);
@mkdir($indexDir, 0775, true);

$code = 'Z901';
$file = $gmlDir . '/' . $code . '_TEST_AUTO_ple.gml';
$fixture = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<root><gml:id>test</gml:id></root>
XML;

$hadFile = is_file($file);
if ($hadFile) {
    @unlink($file);
}

$pass = true;
$errors = [];

try {
    analyticspro_gml_build_catalog(true);
    $catalogBefore = analyticspro_gml_build_catalog(false);
    if (isset($catalogBefore[$code])) {
        $pass = false;
        $errors[] = 'Precondizione fallita: codice test già presente nel catalogo';
    }

    file_put_contents($file, $fixture);
    clearstatcache(true, $file);
    usleep(300000);

    $catalogAfter = analyticspro_gml_build_catalog(false);
    if (!isset($catalogAfter[$code])) {
        $pass = false;
        $errors[] = 'Nuovo file GML non rilevato senza force';
    }
} finally {
    @unlink($file);
    analyticspro_gml_build_catalog(true);
}

if ($pass) {
    echo "PASS: catalogo auto-invalidante OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: {$error}\n";
}
exit(1);
