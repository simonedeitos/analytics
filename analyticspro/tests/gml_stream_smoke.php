<?php

declare(strict_types=1);

/**
 * Smoke test per il parser GML streaming e il calcolo del centroide.
 *
 * Eseguire con:  php analyticspro/tests/gml_stream_smoke.php
 * Exit code 0 = OK, 1 = FAIL.
 */

require dirname(__DIR__) . '/includes/gml_stream_parser.php';
require dirname(__DIR__) . '/includes/geo_centroid.php';

// Bootstrapping minimo per caricare gml_catalog.php in modo standalone
if (!defined('ANALYTICSPRO_ROOT')) {
    define('ANALYTICSPRO_ROOT', dirname(__DIR__));
}
// Stub di analyticspro_db() per i test standalone (non viene usata nelle funzioni testate)
if (!function_exists('analyticspro_db')) {
    function analyticspro_db(): never
    {
        throw new RuntimeException('analyticspro_db() non disponibile in modalità test');
    }
}
require_once dirname(__DIR__) . '/includes/gml_catalog.php';

$FIXTURE = dirname(__DIR__) . '/tests/fixtures/B394_test_ple.gml';

function stream_assert(bool $cond, string $msg): void
{
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

// ============================================================
// 1. Parser streaming: numero corretto di feature
// ============================================================
$count    = 0;
$features = [];
analyticspro_gml_stream_parcels($FIXTURE, static function (array $f) use (&$count, &$features): bool {
    $count++;
    $features[] = $f;
    return false;
});

stream_assert($count === 3, "Attese 3 particelle, lette {$count} (regressione bug XMLReader::next)");

// ============================================================
// 2. analyticspro_gml_codice_foglio
// ============================================================
stream_assert(analyticspro_gml_codice_foglio('33') === '003300', 'codice_foglio(33) deve essere 003300');
stream_assert(analyticspro_gml_codice_foglio('33', 'A') === '0033A0', 'codice_foglio(33, A) deve essere 0033A0');
stream_assert(analyticspro_gml_codice_foglio('5000') === '500000', 'codice_foglio(5000) deve essere 500000');

// ============================================================
// 3. Scomposizione B394_005000.1
// ============================================================
$parts = analyticspro_gml_parse_ref('B394_005000.1');
stream_assert($parts !== null, 'parse_ref B394_005000.1 deve restituire array');
stream_assert(($parts['belfiore'] ?? '') === 'B394', 'belfiore deve essere B394');
stream_assert(($parts['codFoglio'] ?? '') === '005000', 'codFoglio deve essere 005000');
stream_assert(($parts['particella'] ?? '') === '1', 'particella deve essere 1');

// ============================================================
// 4. Variante senza separatore: M393B000300.369
// ============================================================
$parts2 = analyticspro_gml_parse_ref('M393B000300.369');
stream_assert($parts2 !== null, 'parse_ref M393B000300.369 deve restituire array');
stream_assert(($parts2['belfiore'] ?? '') === 'M393', 'belfiore variante deve essere M393');
stream_assert(($parts2['codFoglio'] ?? '') === '000300', 'codFoglio variante deve essere 000300');
stream_assert(($parts2['particella'] ?? '') === '369', 'particella variante deve essere 369');

// ============================================================
// 5. Le feature lette dal file di fixture hanno i campi attesi
// ============================================================
$f1 = $features[0];
stream_assert($f1['belfiore'] === 'B394', 'belfiore della prima particella deve essere B394');
stream_assert($f1['codFoglio'] === '003300', 'codFoglio della prima particella deve essere 003300');
stream_assert($f1['particella'] === '147', 'particella deve essere 147');
stream_assert(count($f1['ext']) === 1, 'prima particella deve avere un ring esterno');
stream_assert(count($f1['int']) === 0, 'prima particella non deve avere ring interni');

// Terza particella (con buco)
$f3 = $features[2];
stream_assert(count($f3['int']) === 1, 'terza particella deve avere un ring interno (buco)');

// ============================================================
// 6. Centroide quadrato noto → 45.5, 10.5
// ============================================================
$square = [
    ['lat' => 45.49, 'lng' => 10.49],
    ['lat' => 45.51, 'lng' => 10.49],
    ['lat' => 45.51, 'lng' => 10.51],
    ['lat' => 45.49, 'lng' => 10.51],
    ['lat' => 45.49, 'lng' => 10.49],
];
$c = analyticspro_centroid($square);
stream_assert($c !== null, 'centroide del quadrato non deve essere null');
stream_assert(abs($c['lat'] - 45.50) < 1e-6, 'centroide lat deve essere ≈ 45.50');
stream_assert(abs($c['lng'] - 10.50) < 1e-6, 'centroide lng deve essere ≈ 10.50');

// ============================================================
// 7. Centroide poligono a L → punto DENTRO il poligono
// ============================================================
$ring2 = $features[1]['ext'][0];
stream_assert($ring2 !== null && count($ring2) >= 3, 'ring esterno del poligono L deve esistere');
$pt2 = analyticspro_interior_point($ring2);
stream_assert($pt2 !== null, 'interior_point del poligono L non deve essere null');
stream_assert(analyticspro_point_in_polygon($pt2, $ring2), 'interior_point del poligono L deve essere dentro il poligono');

// ============================================================
// 8. Punto interno di un poligono con buco: non deve cadere nel buco
// ============================================================
$extRing3 = $features[2]['ext'][0];
$intRing3 = $features[2]['int'][0];
stream_assert($extRing3 !== null, 'ring esterno della terza particella deve esistere');
stream_assert($intRing3 !== null, 'ring interno della terza particella deve esistere');
// Nota: il centroide grezzo può coincidere col centro del buco (caso simmetrico)
// Per questo usiamo analyticspro_interior_point che gestisce il fallback scanline
$pt3 = analyticspro_interior_point($extRing3, [$intRing3]);
stream_assert($pt3 !== null, 'interior_point poligono con buco non deve essere null');
// Verifica che il punto sia dentro l'esterno e non nel buco
stream_assert(analyticspro_point_in_polygon($pt3, $extRing3), 'interior_point deve essere dentro il ring esterno');
$inHole3 = analyticspro_point_in_polygon($pt3, $intRing3);
stream_assert(!$inHole3, 'interior_point poligono con buco non deve cadere nel buco');

// ============================================================
// 9. numberMatched nel file fixture
// ============================================================
$nm = analyticspro_gml_number_matched($FIXTURE);
stream_assert($nm === 3, 'numberMatched nel fixture deve essere 3, ottenuto: ' . var_export($nm, true));

// ============================================================
// 10. gml_strip_bounded_by esclude correttamente l'Envelope
// ============================================================
$xmlWithEnvelope = '<root><gml:boundedBy><gml:Envelope><gml:lowerCorner>1 2</gml:lowerCorner></gml:Envelope></gml:boundedBy><gml:exterior><gml:LinearRing><gml:posList>45 10 45 11 46 10 45 10</gml:posList></gml:LinearRing></gml:exterior></root>';
$rings = analyticspro_gml_extract_rings($xmlWithEnvelope);
stream_assert(count($rings['ext']) === 1, 'deve estrarre un solo ring esterno (boundedBy ignorato)');
$pts = $rings['ext'][0];
// I punti 1,2 del boundedBy non devono essere inclusi
$latValues = array_column($pts, 'lat');
stream_assert(!in_array(1.0, $latValues, true), 'le coordinate del boundedBy non devono comparire nel ring esterno');

echo "OK — tutti i test superati\n";

// ============================================================
// 11. analyticspro_gml_norm_particella
// ============================================================
stream_assert(analyticspro_gml_norm_particella('0147') === '147',   'norm_particella(0147) deve essere 147');
stream_assert(analyticspro_gml_norm_particella('147/A') === '147A', 'norm_particella(147/A) deve essere 147A');
stream_assert(analyticspro_gml_norm_particella(' 147 ') === '147',  'norm_particella( 147 ) deve essere 147');
stream_assert(analyticspro_gml_norm_particella('147a') === '147A',  'norm_particella(147a) deve essere 147A');

// ============================================================
// 12. analyticspro_gml_codice_foglio con lettera nel campo foglio
// ============================================================
stream_assert(analyticspro_gml_codice_foglio('33A') === '0033A0',  'codice_foglio(33A) deve essere 0033A0');
stream_assert(analyticspro_gml_codice_foglio('33', 'A') === '0033A0', 'codice_foglio(33,A) deve essere 0033A0');
stream_assert(analyticspro_gml_codice_foglio('33') === '003300',   'codice_foglio(33) invariato deve essere 003300');

// ============================================================
// 13. analyticspro_gml_sanitize_filename — nomi reali ADE
// ============================================================
$CATALOG_PATTERN = '/^([A-Za-z][0-9]{3})_(.+?)_(ple|map)\.gml$/i';

$s1 = analyticspro_gml_sanitize_filename("F704_SANT'ANGELO_ple.gml");
stream_assert($s1 !== null, "sanitize_filename(SANT'ANGELO) non deve essere null");
stream_assert(preg_match($CATALOG_PATTERN, (string) $s1) === 1, "sanitize_filename(SANT'ANGELO) deve fare match con la regex del catalogo");

$s2 = analyticspro_gml_sanitize_filename('B394_CASTIGLIONE DELLE STIVIERE_ple.gml');
stream_assert($s2 !== null, 'sanitize_filename(CASTIGLIONE DELLE STIVIERE) non deve essere null');
stream_assert(preg_match($CATALOG_PATTERN, (string) $s2) === 1, 'sanitize_filename(CASTIGLIONE DELLE STIVIERE) deve fare match con la regex del catalogo');

// Path traversal → null
$s3 = analyticspro_gml_sanitize_filename('../../etc/passwd');
stream_assert($s3 === null, 'sanitize_filename(../../etc/passwd) deve essere null (path traversal)');

// ============================================================
// 14. Nome comune estratto dal filename
// ============================================================
// Simula la logica del catalogo: ucwords(mb_strtolower(str_replace('_', ' ', $m[2])))
$filenameRaw = 'B394_CALCINATO_ple.gml';
preg_match('/^([A-Za-z][0-9]{3})_(.+?)_(ple|map)\.gml$/i', $filenameRaw, $mTest);
$nomeTest = ucwords(mb_strtolower(str_replace('_', ' ', $mTest[2])));
stream_assert($nomeTest === 'Calcinato', 'Nome dal filename B394_CALCINATO_ple.gml deve essere "Calcinato", ottenuto: ' . $nomeTest);

// ============================================================
// 15. Area con buco: area netta < area lorda del ring esterno
// ============================================================
$extRing3 = $features[2]['ext'][0];
$intRing3 = $features[2]['int'][0];
$areaLorda = analyticspro_ring_area_m2($extRing3);
$areaBuco  = analyticspro_ring_area_m2($intRing3);
$areaNetta = $areaLorda - $areaBuco;
stream_assert($areaNetta < $areaLorda, 'Area netta (con buco) deve essere < area lorda del ring esterno');
stream_assert($areaNetta > 0.0, 'Area netta (con buco) deve essere > 0');

// ============================================================
// 16. Centroide con ring interno di verso CCW (stesso verso dell'esterno — caso non standard)
// ============================================================
// Crea un quadrato esterno CCW e un buco CCW (stesso verso dell'esterno).
// Il codice originale usava sign=-1 per i buchi, che funzionava correttamente
// solo se il buco era CW. Con la nuova implementazione basata su abs() il
// risultato è corretto indipendentemente dall'orientamento.
$outerCCW = [
    ['lat' => 45.00, 'lng' => 10.00],
    ['lat' => 45.02, 'lng' => 10.00],
    ['lat' => 45.02, 'lng' => 10.02],
    ['lat' => 45.00, 'lng' => 10.02],
    ['lat' => 45.00, 'lng' => 10.00],
];
// Buco CCW (stesso verso dell'esterno — caso che il codice originale non gestiva)
$innerCCW = [
    ['lat' => 45.009, 'lng' => 10.009],
    ['lat' => 45.011, 'lng' => 10.009],
    ['lat' => 45.011, 'lng' => 10.011],
    ['lat' => 45.009, 'lng' => 10.011],
    ['lat' => 45.009, 'lng' => 10.009],
];
$ptHole = analyticspro_interior_point($outerCCW, [$innerCCW]);
stream_assert($ptHole !== null, 'interior_point con buco CCW non deve essere null');
stream_assert(analyticspro_point_in_polygon($ptHole, $outerCCW), 'interior_point con buco CCW deve essere dentro il ring esterno');
stream_assert(!analyticspro_point_in_polygon($ptHole, $innerCCW), 'interior_point con buco CCW non deve essere nel buco');

echo "OK — tutti i test aggiuntivi superati\n";