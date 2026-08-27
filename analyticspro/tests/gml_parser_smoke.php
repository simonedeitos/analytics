<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/gml_parser.php';

function analyticspro_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$mapserverGml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<wfs:FeatureCollection xmlns:wfs="http://www.opengis.net/wfs/2.0" xmlns:gml="http://www.opengis.net/gml/3.2" xmlns:CP="http://mapserver.gis.umn.edu/mapserver">
  <wfs:member>
    <CP:CadastralParcel gml:id="CadastralParcel.IT.AGE.PLA.D185_090400.1">
      <CP:msGeometry>
        <gml:Polygon gml:id="poly1" srsName="urn:ogc:def:crs:EPSG::6706">
          <gml:exterior>
            <gml:LinearRing>
              <gml:posList srsDimension="2">45.76014260 8.76933700 45.76009199 8.76939650 45.76016900 8.76951100 45.76014260 8.76933700</gml:posList>
            </gml:LinearRing>
          </gml:exterior>
        </gml:Polygon>
      </CP:msGeometry>
      <CP:INSPIREID_LOCALID>IT.AGE.PLA.D185_090400.1</CP:INSPIREID_LOCALID>
      <CP:INSPIREID_NAMESPACE>IT.AGE.PLA.</CP:INSPIREID_NAMESPACE>
      <CP:LABEL>1</CP:LABEL>
      <CP:NATIONALCADASTRALREFERENCE>D185_090400.1</CP:NATIONALCADASTRALREFERENCE>
    </CP:CadastralParcel>
  </wfs:member>
</wfs:FeatureCollection>
XML;

$tmpPath = tempnam(sys_get_temp_dir(), 'gml_mapserver_');
if ($tmpPath === false) {
    fwrite(STDERR, "FAIL: could not create temporary file\n");
    exit(1);
}
file_put_contents($tmpPath, $mapserverGml);

$parcels = analyticspro_parse_cadastral_parcels_gml($tmpPath);
@unlink($tmpPath);

analyticspro_test_assert(count($parcels) === 1, 'attesa 1 particella dal dialetto MapServer/AdE');
analyticspro_test_assert(($parcels[0]['inspire_id'] ?? null) === 'IT.AGE.PLA.D185_090400.1', 'inspire_id non estratto correttamente');
analyticspro_test_assert(($parcels[0]['national_reference'] ?? null) === 'D185_090400.1', 'national reference non estratto correttamente');
analyticspro_test_assert(is_array($parcels[0]['points'] ?? null) && count($parcels[0]['points']) >= 4, 'poligono non estratto correttamente');

$parts = analyticspro_extract_cadastral_parts('D185_090400.1', '1');
analyticspro_test_assert(is_array($parts), 'riferimento catastale AdE non parsato');
analyticspro_test_assert(($parts['foglio'] ?? null) === '090400', 'foglio AdE deve preservare il blocco numerico completo');
analyticspro_test_assert(($parts['particella'] ?? null) === '1', 'particella AdE non estratta');

echo "OK\n";

$inspireGml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<wfs:FeatureCollection xmlns:wfs="http://www.opengis.net/wfs/2.0" xmlns:gml="http://www.opengis.net/gml/3.2" xmlns:cp="http://inspire.ec.europa.eu/schemas/cp/4.0" xmlns:base="http://inspire.ec.europa.eu/schemas/base/3.3">
  <wfs:member>
    <cp:CadastralParcel>
      <cp:geometry>
        <gml:Polygon>
          <gml:exterior>
            <gml:LinearRing>
              <gml:posList>45.0 8.0 45.0 8.1 45.1 8.1 45.0 8.0</gml:posList>
            </gml:LinearRing>
          </gml:exterior>
        </gml:Polygon>
      </cp:geometry>
      <cp:inspireId>
        <base:Identifier>
          <base:localId>LOCAL-123</base:localId>
          <base:namespace>IT.TEST.</base:namespace>
        </base:Identifier>
      </cp:inspireId>
      <cp:label>123</cp:label>
      <cp:nationalCadastralReference>A123.12.34</cp:nationalCadastralReference>
      <cp:areaValue uom="m2">100</cp:areaValue>
    </cp:CadastralParcel>
  </wfs:member>
</wfs:FeatureCollection>
XML;

$tmpInspirePath = tempnam(sys_get_temp_dir(), 'gml_inspire_');
if ($tmpInspirePath === false) {
    fwrite(STDERR, "FAIL: could not create temporary INSPIRE file\n");
    exit(1);
}
file_put_contents($tmpInspirePath, $inspireGml);

$inspireParcels = analyticspro_parse_cadastral_parcels_gml($tmpInspirePath);
@unlink($tmpInspirePath);

analyticspro_test_assert(count($inspireParcels) === 1, 'attesa 1 particella dal dialetto INSPIRE');
analyticspro_test_assert(($inspireParcels[0]['inspire_id'] ?? null) === 'IT.TEST.LOCAL-123', 'inspireId INSPIRE non composto correttamente');
analyticspro_test_assert((float) ($inspireParcels[0]['area_mq'] ?? 0) === 100.0, 'areaValue INSPIRE non estratto');

echo "OK INSPIRE\n";
