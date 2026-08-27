<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/ade_import.php';

function analyticspro_sql_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$sql = <<<'SQL'
-- sezione regione
INSERT INTO cadastral_comuni (provincia_sigla, cod_catastale, nome_comune, map_gml_filename, ple_gml_filename)
VALUES ('MI', 'F205', 'MILANO', 'F205_map.gml', 'F205_ple.gml');

INSERT INTO cadastral_parcels (comune_id, cod_catastale, sezione, foglio, particella, geom, interior_point, area_mq, source_file)
SELECT id, 'F205', 'A', '0001', '12', ST_GeomFromText('POLYGON((45.1 9.1, 45.2 9.1, 45.2 9.2, 45.1 9.1))', 4326), ST_GeomFromText('POINT(45.15 9.15)', 4326), 123.45, 'F205_ple.gml'
FROM cadastral_comuni
WHERE cod_catastale = 'F205'
ON DUPLICATE KEY UPDATE
    geom = VALUES(geom),
    source_file = VALUES(source_file);

/* commento con ; che non deve spezzare */
INSERT INTO audit_log(message) VALUES('ciao; ancora ciao');
SQL;

$tmpPath = tempnam(sys_get_temp_dir(), 'ade_sql_');
if ($tmpPath === false) {
    fwrite(STDERR, "FAIL: could not create temporary SQL file\n");
    exit(1);
}

file_put_contents($tmpPath, $sql);

$statements = [];
analyticspro_ade_stream_sql_statements($tmpPath, static function (string $statement) use (&$statements): void {
    $statements[] = $statement;
});

$analysis = analyticspro_ade_analyze_sql_file($tmpPath);
@unlink($tmpPath);

analyticspro_sql_test_assert(count($statements) === 3, 'attesi 3 statement SQL dal parser streaming');
analyticspro_sql_test_assert(analyticspro_ade_classify_sql_statement($statements[0]) === 'comune', 'il primo statement deve essere classificato come comune');
analyticspro_sql_test_assert(analyticspro_ade_classify_sql_statement($statements[1]) === 'parcel', 'il secondo statement deve essere classificato come particella');
analyticspro_sql_test_assert(analyticspro_ade_classify_sql_statement($statements[2]) === 'other', 'il terzo statement deve essere classificato come altro');
analyticspro_sql_test_assert((int) $analysis['total_statements'] === 3, 'conteggio totale statement errato');
analyticspro_sql_test_assert((int) $analysis['total_comuni'] === 1, 'conteggio INSERT comuni errato');
analyticspro_sql_test_assert((int) $analysis['total_particelle'] === 1, 'conteggio INSERT particelle errato');
analyticspro_sql_test_assert((int) $analysis['total_other'] === 1, 'conteggio altri statement errato');

echo "OK SQL\n";
