<?php

declare(strict_types=1);

$root = sys_get_temp_dir() . '/analyticspro_resolution_' . getmypid();
$storageGml = $root . '/storage/gml';
$storageIndex = $root . '/storage/gml_index';
@mkdir($storageGml, 0775, true);
@mkdir($storageIndex, 0775, true);

register_shutdown_function(static function () use ($root): void {
    if (!is_dir($root)) {
        return;
    }
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

$logPath = $root . '/php-error.log';
ini_set('error_log', $logPath);

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
    $pdo->exec("INSERT INTO cadastral_comuni (cod_catastale, provincia_sigla, nome_comune) VALUES
        ('A111', 'BG', 'San Pietro'),
        ('B222', 'BS', 'San Pietro'),
        ('T123', 'ZZ', 'Test Db Comune')
    ");
    return $pdo;
}

require_once dirname(__DIR__) . '/includes/gml_stream_parser.php';
require_once dirname(__DIR__) . '/includes/geo_centroid.php';
require_once dirname(__DIR__) . '/includes/gml_catalog.php';
require_once dirname(__DIR__) . '/includes/wfs_lookup.php';
require_once dirname(__DIR__) . '/includes/importer.php';

function resolution_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$calcinatoGml = <<<'XML'
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

file_put_contents($storageGml . '/B394_CALCINATO_ple.gml', $calcinatoGml);
file_put_contents($storageGml . '/F704_SANTANGELO_ple.gml', '<xml/>');
file_put_contents($storageGml . '/C312_CASTIGLIONE_DELLE_STIVIERE_ple.gml', '<xml/>');
file_put_contents($storageGml . '/A111_SAN_PIETRO_ple.gml', '<xml/>');
file_put_contents($storageGml . '/B222_SAN_PIETRO_ple.gml', '<xml/>');

analyticspro_gml_build_catalog(true);

resolution_assert(analyticspro_gml_belfiore_da_comune('Calcinato') === 'B394', 'Calcinato deve risolvere B394');
resolution_assert(analyticspro_gml_belfiore_da_comune('CALCINATO') === 'B394', 'CALCINATO deve risolvere B394');
resolution_assert(analyticspro_gml_belfiore_da_comune('calcinato') === 'B394', 'calcinato deve risolvere B394');
resolution_assert(analyticspro_gml_belfiore_da_comune("Sant'Angelo") === 'F704', "Sant'Angelo deve risolvere F704");
resolution_assert(analyticspro_gml_belfiore_da_comune('Castiglione delle Stiviere') === 'C312', 'Castiglione delle Stiviere deve risolvere C312');
resolution_assert(analyticspro_gml_belfiore_da_comune('Castiglione D/Stiviere') === 'C312', 'Castiglione D/Stiviere deve risolvere C312');

@unlink($logPath);
$ambiguous = analyticspro_gml_belfiore_da_comune('San Pietro');
resolution_assert($ambiguous === null, 'San Pietro senza provincia deve restituire null per omonimia');
$logContent = is_file($logPath) ? (string) file_get_contents($logPath) : '';
resolution_assert(str_contains($logContent, 'San Pietro') && str_contains($logContent, 'A111') && str_contains($logContent, 'B222'), 'L\'ambiguità deve essere loggata con i candidati');

$byCode = analyticspro_gml_lookup('B394', '34', '351');
$byName = analyticspro_gml_lookup('Calcinato', '34', '351');
resolution_assert($byCode !== null && $byName !== null, 'Il lookup GML deve funzionare sia per codice che per nome comune');
resolution_assert(abs((float) $byCode['lat'] - (float) $byName['lat']) < 0.000001, 'Lookup GML nome/codice deve produrre la stessa latitudine');
resolution_assert(abs((float) $byCode['lon'] - (float) $byName['lon']) < 0.000001, 'Lookup GML nome/codice deve produrre la stessa longitudine');

$memo = [];
$first = null;
for ($i = 1; $i <= 30; $i++) {
    $resolved = analyticspro_resolve_parcel_coordinates([
        'comune' => 'CALCINATO',
        'provincia' => 'BS',
        'cod_catastale' => '',
        'foglio' => '34',
        'particella' => '351',
        'subalterno' => (string) $i,
    ], $memo);
    resolution_assert(($resolved['coord_source'] ?? null) === 'gml_locale', 'Le coordinate devono provenire dal GML locale');
    if ($first === null) {
        $first = $resolved;
        continue;
    }
    resolution_assert(abs((float) $first['lat'] - (float) $resolved['lat']) < 0.000001, 'Stessa particella con sub diversi deve avere stessa latitudine');
    resolution_assert(abs((float) $first['lng'] - (float) $resolved['lng']) < 0.000001, 'Stessa particella con sub diversi deve avere stessa longitudine');
}
resolution_assert((int) ($memo['stats']['gml_lookup_calls'] ?? 0) === 1, 'Memoizzazione: 30 righe stessa particella devono fare 1 solo lookup GML');

$dbPath = $storageIndex . '/collision.sqlite';
$db = new SQLite3($dbPath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);
$db->exec('CREATE TABLE parcels (cod_foglio TEXT NOT NULL, particella TEXT NOT NULL, particella_norm TEXT NOT NULL, lat REAL NOT NULL, lon REAL NOT NULL, area_mq REAL NOT NULL DEFAULT 0, PRIMARY KEY (cod_foglio, particella))');
$db->exec('CREATE INDEX idx_norm ON parcels(cod_foglio, particella_norm)');
$db->exec("INSERT INTO parcels VALUES ('003400', 'ACQUA310', 'ACQUA310', 45.1, 10.1, 1)");
$db->exec("INSERT INTO parcels VALUES ('003400', '310', '310', 45.2, 10.2, 1)");
$db->exec('CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT)');
$db->exec("INSERT INTO meta VALUES ('complete', '1')");
$db->close();

resolution_assert(analyticspro_gml_norm_particella('ACQUA310') === 'ACQUA310', 'La normalizzazione deve preservare il prefisso alfabetico');
resolution_assert(analyticspro_gml_norm_particella('310') === '310', 'La particella numerica deve restare distinta');
$hitAlpha = analyticspro_gml_lookup_in_index($dbPath, '003400', 'ACQUA310', analyticspro_gml_norm_particella('ACQUA310'));
$hitNum = analyticspro_gml_lookup_in_index($dbPath, '003400', '310', analyticspro_gml_norm_particella('310'));
resolution_assert($hitAlpha !== null && abs((float) $hitAlpha['lat'] - 45.1) < 0.0001, 'ACQUA310 non deve collidere con 310');
resolution_assert($hitNum !== null && abs((float) $hitNum['lat'] - 45.2) < 0.0001, '310 deve restare risolvibile separatamente');

$resolvedExplicit = analyticspro_resolve_cod_catastale('B394', 'Qualsiasi', 'BS');
resolution_assert(($resolvedExplicit['source'] ?? '') === 'esplicito', 'La catena deve usare il codice esplicito quando valido');

$resolvedGml = analyticspro_resolve_cod_catastale('', 'Calcinato', '');
resolution_assert(($resolvedGml['cod'] ?? null) === 'B394' && ($resolvedGml['source'] ?? '') === 'gml_catalogo', 'La catena deve usare il catalogo GML come secondo livello');

$resolvedJson = analyticspro_resolve_cod_catastale('', 'Monza', 'MB');
resolution_assert(($resolvedJson['cod'] ?? null) === 'F704' && ($resolvedJson['source'] ?? '') === 'comuni_catastali_json', 'La catena deve usare comuni_catastali.json come terzo livello');

$resolvedDb = analyticspro_resolve_cod_catastale('', 'Test Db Comune', 'ZZ');
resolution_assert(($resolvedDb['cod'] ?? null) === 'T123' && ($resolvedDb['source'] ?? '') === 'db_cadastral', 'La catena deve usare cadastral_comuni come quarto livello');

echo "OK — tutti i test di risoluzione GML/Belfiore superati\n";
