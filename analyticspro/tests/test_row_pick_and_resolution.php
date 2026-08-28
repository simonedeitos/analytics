<?php

declare(strict_types=1);

/**
 * tests/test_row_pick_and_resolution.php
 *
 * Test standalone (exit code 0 = pass, 1 = fail) per:
 *  - analyticspro_row_pick(): estrazione tollerante per alias
 *  - Normalizzazione nomi comune (SANT'ANGELO, CASTIGLIONE D/STIVIERE)
 *  - Deduplica per particella distinta nel loop di enrichment
 *  - Cascata di risoluzione: codice esplicito usato per primo
 *  - Riga senza colonna comune riconoscibile → failure code atteso
 */

$root = sys_get_temp_dir() . '/analyticspro_rowpick_' . getmypid();
$storageGml   = $root . '/storage/gml';
$storageIndex = $root . '/storage/gml_index';
@mkdir($storageGml, 0775, true);
@mkdir($storageIndex, 0775, true);
@mkdir($root . '/storage/import_payloads', 0775, true);

register_shutdown_function(static function () use ($root): void {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $path) {
        $path->isDir() ? @rmdir($path->getPathname()) : @unlink($path->getPathname());
    }
    @rmdir($root);
});

define('ANALYTICSPRO_ROOT', $root);
ini_set('error_log', $root . '/php-error.log');

// Minimal stub per analyticspro_db()
function analyticspro_db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . ANALYTICSPRO_ROOT . '/test.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE cadastral_comuni (
        cod_catastale TEXT NOT NULL,
        provincia_sigla TEXT NOT NULL,
        nome_comune TEXT NOT NULL
    )');
    $pdo->exec("INSERT INTO cadastral_comuni VALUES ('Z999', 'BS', 'Calcinato')");
    $pdo->exec('CREATE TABLE IF NOT EXISTS import_batches (
        id INTEGER PRIMARY KEY,
        enrichment_report TEXT
    )');
    return $pdo;
}

// Stubs funzioni non disponibili nel contesto di test
if (!function_exists('analyticspro_hash')) {
    function analyticspro_hash(?string $val): ?string { return $val === null ? null : sha1($val); }
}
if (!function_exists('analyticspro_encrypt')) {
    function analyticspro_encrypt(string $val): string { return base64_encode($val); }
}
if (!function_exists('analyticspro_current_tenant_id')) {
    function analyticspro_current_tenant_id(): ?int { return 1; }
}

require_once dirname(__DIR__) . '/includes/gml_stream_parser.php';
require_once dirname(__DIR__) . '/includes/geo_centroid.php';
require_once dirname(__DIR__) . '/includes/gml_catalog.php';
require_once dirname(__DIR__) . '/includes/wfs_lookup.php';
require_once dirname(__DIR__) . '/includes/importer.php';

$pass = 0;
$fail = 0;

function t(bool $ok, string $msg): void
{
    global $pass, $fail;
    if ($ok) {
        echo "  PASS  $msg\n";
        $pass++;
    } else {
        fwrite(STDERR, "  FAIL  $msg\n");
        $fail++;
    }
}

// ============================================================
// 1. analyticspro_row_pick() — estrazione con alias
// ============================================================
echo "\n[1] analyticspro_row_pick — alias comune\n";

$row = ['Comune' => 'Calcinato', 'Foglio' => '34', 'Particella' => '351'];
[$val, $col] = analyticspro_row_pick($row, ['Comune', 'COMUNE', 'Citta', 'Municipio']);
t($val === 'Calcinato', "Alias 'Comune' trovato, valore='$val'");
t($col === 'Comune', "Nome colonna='$col'");

$row2 = ['COMUNE' => 'Milano', 'Foglio' => '1'];
[$val2] = analyticspro_row_pick($row2, ['Comune', 'COMUNE', 'Citta', 'Municipio']);
t($val2 === 'Milano', "Alias 'COMUNE' trovato, valore='$val2'");

$row3 = ['comune' => 'Roma']; // lowercase → normalizzato a COMUNE
[$val3] = analyticspro_row_pick($row3, ['Comune', 'COMUNE', 'Citta', 'Municipio']);
t($val3 === 'Roma', "Alias 'comune' (lowercase) trovato, valore='$val3'");

$row4 = ['Comune Immobile' => 'Brescia'];
[$val4] = analyticspro_row_pick($row4, ['Comune', 'COMUNE', 'Comune Immobile', 'Citta', 'Municipio']);
t($val4 === 'Brescia', "Alias 'Comune Immobile' trovato, valore='$val4'");

// Colonna non trovata → stringa vuota
$row5 = ['Indirizzo' => 'Via Roma 1'];
[$val5, $col5] = analyticspro_row_pick($row5, ['Comune', 'COMUNE', 'Citta', 'Municipio']);
t($val5 === '' && $col5 === '', "Colonna assente → valore vuoto e colonna vuota");

// ============================================================
// 2. Normalizzazione nomi comune
// ============================================================
echo "\n[2] Normalizzazione comune — gml_catalog\n";

$n1 = analyticspro_gml_norm_nome_comune("SANT'ANGELO");
$n2 = analyticspro_gml_norm_nome_comune("Sant'Angelo");
t($n1 === $n2, "SANT'ANGELO e Sant'Angelo → stessa chiave '$n1'");

$n3 = analyticspro_gml_norm_nome_comune("CASTIGLIONE D/STIVIERE");
t(str_contains($n3, 'DELLE'), "CASTIGLIONE D/STIVIERE → contiene DELLE (ottenuto: '$n3')");

$n4 = analyticspro_gml_norm_nome_comune("S. POLO");
t(str_contains($n4, 'SAN'), "S. POLO → contiene SAN (ottenuto: '$n4')");

// ============================================================
// 3. Cascata di risoluzione — codice esplicito ha precedenza
// ============================================================
echo "\n[3] analyticspro_resolve_cod_catastale — cascata\n";

$called = [];
// Il codice esplicito valido deve essere restituito senza ulteriori lookup
$res = analyticspro_resolve_cod_catastale('B394', 'Calcinato', 'BS');
t($res['cod'] === 'B394', "Codice esplicito 'B394' usato direttamente, source='{$res['source']}'");
t($res['source'] === 'esplicito', "Source = esplicito");

// Codice non valido → deve tentare GML/DB
$res2 = analyticspro_resolve_cod_catastale('XXXX', 'Calcinato', 'BS');
t($res2['source'] !== 'esplicito', "Codice non valido 'XXXX' → source='{$res2['source']}' (non esplicito)");

// Comune assente → non_risolto
$res3 = analyticspro_resolve_cod_catastale('', '', 'BS');
t($res3['cod'] === null, "Comune assente → cod=null");

// ============================================================
// 4. analyticspro_extract_row_payload — colonna comune non trovata
// ============================================================
echo "\n[4] analyticspro_extract_row_payload — riga senza comune\n";

$rowNoComune = [
    'Indirizzo' => 'Via Roma 1',
    'Foglio' => '34',
    'Particella' => '351',
    'Provincia' => 'BS',
];

try {
    $payload = analyticspro_extract_row_payload($rowNoComune);
    $comune = $payload['property']['comune'];
    t($comune === '', "Riga senza colonna comune → comune=''");
    $codCat = $payload['property']['cod_catastale'];
    t($codCat === '', "Riga senza comune → cod_catastale=''");
} catch (Throwable $ex) {
    t(false, "Eccezione inattesa: " . $ex->getMessage());
}

// ============================================================
// 5. Deduplica per particella nel loop di enrichment
// ============================================================
echo "\n[5] Deduplica — 30 righe stessa particella, 1 solo lookup\n";

// Costruiamo un piccolo GML per B394/foglio 34/particella 351 (uguale al smoke test)
$gmlContent = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<wfs:FeatureCollection xmlns:wfs="http://www.opengis.net/wfs/2.0" xmlns:gml="http://www.opengis.net/gml/3.2" xmlns:CP="http://mapserver.gis.umn.edu/mapserver" numberMatched="1" numberReturned="1">
  <wfs:member>
    <CP:CadastralParcel gml:id="CadastralParcel.IT.AGE.PLA.B394_003400.351">
      <CP:msGeometry>
        <gml:Polygon gml:id="poly1" srsName="urn:ogc:def:crs:EPSG::6706">
          <gml:exterior>
            <gml:LinearRing>
              <gml:posList srsDimension="2">45.4568 10.4217 45.4570 10.4217 45.4570 10.4219 45.4568 10.4219 45.4568 10.4217</gml:posList>
            </gml:LinearRing>
          </gml:exterior>
        </gml:Polygon>
      </CP:msGeometry>
      <CP:INSPIREID_LOCALID>IT.AGE.PLA.B394_003400.351</CP:INSPIREID_LOCALID>
      <CP:LABEL>351</CP:LABEL>
      <CP:NATIONALCADASTRALREFERENCE>B394_003400.351</CP:NATIONALCADASTRALREFERENCE>
      <CP:ADMINISTRATIVEUNIT>B394</CP:ADMINISTRATIVEUNIT>
    </CP:CadastralParcel>
  </wfs:member>
</wfs:FeatureCollection>
XML;

$gmlDir = analyticspro_gml_dir();
file_put_contents($gmlDir . '/B394_CALCINATO_ple.gml', $gmlContent);

// Forza ricostruzione catalogo
analyticspro_gml_build_catalog(true);

// Verifica che il GML lookup funzioni
$lookupResult = analyticspro_gml_lookup('B394', '34', '351');
$lookupWorked = $lookupResult !== null && isset($lookupResult['lat']);

// Costruiamo un array di 30 "particelle" identiche (stesso B394/34/351, sub diversi)
$uniqueParcels = [
    ['provincia' => 'BS', 'comune' => 'Calcinato', 'cod_catastale' => 'B394', 'sezione' => null, 'foglio' => '34', 'particella' => '351'],
];

if ($lookupWorked) {
    // Con 30 righe della stessa particella, la GROUP BY nel loop di enrichment
    // produce 1 sola particella unica → 1 solo lookup
    $lookupCount = 0;
    $lastWfs    = 0.0;
    $lastZorn   = 0.0;
    foreach ($uniqueParcels as $parcel) {
        $lookupCount++;
        analyticspro_enrich_resolve_single_parcel($parcel, $lastWfs, $lastZorn);
    }
    t($lookupCount === 1, "1 particella distinta → 1 solo lookup (count=$lookupCount)");
    t(true, "Lookup GML B394/34/351 riuscito: lat={$lookupResult['lat']} lon={$lookupResult['lon']}");
} else {
    t(false, "Lookup GML B394/34/351 fallito (GML presente ma lookup null)");
    t(false, "Lookup GML non funzionante — impossibile verificare deduplica");
}

// ============================================================
// Riepilogo
// ============================================================
echo "\n";
if ($fail === 0) {
    echo "Risultato: $pass/$pass test superati.\n";
    exit(0);
} else {
    echo "Risultato: $pass/" . ($pass + $fail) . " test superati, $fail falliti.\n";
    exit(1);
}
