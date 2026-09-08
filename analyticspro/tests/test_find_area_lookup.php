<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/gml_catalog.php';

$pass = true;
$errors = [];

$tmpDir = sys_get_temp_dir() . '/gml_find_area_test_' . getmypid();
if (!is_dir($tmpDir) && !mkdir($tmpDir, 0777, true) && !is_dir($tmpDir)) {
    fwrite(STDERR, "Impossibile creare directory temporanea\n");
    exit(1);
}
$dbPath = $tmpDir . '/B394.sqlite';
$db = new SQLite3($dbPath);
$db->exec('
    CREATE TABLE parcels (
        cod_foglio TEXT NOT NULL,
        particella TEXT NOT NULL,
        particella_norm TEXT NOT NULL,
        lat REAL NOT NULL,
        lon REAL NOT NULL,
        area_mq REAL NOT NULL DEFAULT 0,
        PRIMARY KEY (cod_foglio, particella)
    );
');
$db->exec("INSERT INTO parcels (cod_foglio, particella, particella_norm, lat, lon, area_mq) VALUES
    ('003300', '147', '147', 45.4890, 10.4100, 123.0),
    ('003300', '148', '148', 45.4910, 10.4140, 111.0),
    ('0033A0', '149', '149', 45.4950, 10.4200, 98.0),
    ('003400', '1',   '1',   45.6000, 10.6000, 50.0)
");
$db->close();

$bounds = analyticspro_gml_lookup_foglio_bounds('B394', '33', '', '', $dbPath);
if ($bounds === null) {
    $pass = false;
    $errors[] = 'Bounds foglio 33 non trovati.';
} else {
    if (abs(((float) $bounds['min_lat']) - 45.4890) > 0.000001 || abs(((float) $bounds['max_lat']) - 45.4950) > 0.000001) {
        $pass = false;
        $errors[] = 'Range latitudine foglio 33 non corretto.';
    }
    if (abs(((float) $bounds['min_lng']) - 10.4100) > 0.000001 || abs(((float) $bounds['max_lng']) - 10.4200) > 0.000001) {
        $pass = false;
        $errors[] = 'Range longitudine foglio 33 non corretto.';
    }
}

$emptyBounds = analyticspro_gml_lookup_foglio_bounds('B394', '999', '', '', $dbPath);
if ($emptyBounds !== null) {
    $pass = false;
    $errors[] = 'Foglio inesistente non deve restituire bounds.';
}

@unlink($dbPath);
@rmdir($tmpDir);

if ($pass) {
    echo "PASS: lookup area foglio OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: {$error}\n";
}
exit(1);
