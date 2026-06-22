<?php
/**
 * api/get_particelle_wfs.php
 * Query WFS INSPIRE Agenzia Entrate per ottenere centroid precisi delle particelle catastali.
 */
date_default_timezone_set('Europe/Rome');

define('CACHE_DIR', __DIR__ . '/../cache/particelle_wfs');
define('CACHE_MAX_DAYS', 30);
define('WFS_ENDPOINT', 'https://wfs.cartografia.agenziaentrate.gov.it/inspire/wfs/ows01.php');
define('BBOX_RADIUS', 0.03); // ~3km
define('TILE_SIZE', 0.01);   // ~1km
define('WFS_TIMEOUT', 30);
define('TILE_DELAY_US', 500000); // 0.5 sec
define('METERS_PER_DEGREE', 111000);

const PROVINCE_MAP = [
    'AGRIGENTO' => 'AG', 'ALESSANDRIA' => 'AL', 'ANCONA' => 'AN', 'AOSTA' => 'AO',
    'AREZZO' => 'AR', 'ASCOLI PICENO' => 'AP', 'ASTI' => 'AT', 'AVELLINO' => 'AV',
    'BARI' => 'BA', 'BARLETTA-ANDRIA-TRANI' => 'BT', 'BELLUNO' => 'BL', 'BENEVENTO' => 'BN',
    'BERGAMO' => 'BG', 'BIELLA' => 'BI', 'BOLOGNA' => 'BO', 'BOLZANO' => 'BZ',
    'BRESCIA' => 'BS', 'BRINDISI' => 'BR', 'CAGLIARI' => 'CA', 'CALTANISSETTA' => 'CL',
    'CAMPOBASSO' => 'CB', 'CASERTA' => 'CE', 'CATANIA' => 'CT', 'CATANZARO' => 'CZ',
    'CHIETI' => 'CH', 'COMO' => 'CO', 'COSENZA' => 'CS', 'CREMONA' => 'CR',
    'CROTONE' => 'KR', 'CUNEO' => 'CN', 'ENNA' => 'EN', 'FERMO' => 'FM',
    'FERRARA' => 'FE', 'FIRENZE' => 'FI', 'FOGGIA' => 'FG', 'FORLI-CESENA' => 'FC',
    'FROSINONE' => 'FR', 'GENOVA' => 'GE', 'GORIZIA' => 'GO', 'GROSSETO' => 'GR',
    'IMPERIA' => 'IM', 'ISERNIA' => 'IS', 'LA SPEZIA' => 'SP', 'LAQUILA' => 'AQ',
    'LATINA' => 'LT', 'LECCE' => 'LE', 'LECCO' => 'LC', 'LIVORNO' => 'LI',
    'LODI' => 'LO', 'LUCCA' => 'LU', 'MACERATA' => 'MC', 'MANTOVA' => 'MN',
    'MASSA-CARRARA' => 'MS', 'MATERA' => 'MT', 'MESSINA' => 'ME', 'MILANO' => 'MI',
    'MODENA' => 'MO', 'MONZA E BRIANZA' => 'MB', 'NAPOLI' => 'NA', 'NOVARA' => 'NO',
    'NUORO' => 'NU', 'ORISTANO' => 'OR', 'PADOVA' => 'PD', 'PALERMO' => 'PA',
    'PARMA' => 'PR', 'PAVIA' => 'PV', 'PERUGIA' => 'PG', 'PESARO E URBINO' => 'PU',
    'PESCARA' => 'PE', 'PIACENZA' => 'PC', 'PISA' => 'PI', 'PISTOIA' => 'PT',
    'PORDENONE' => 'PN', 'POTENZA' => 'PZ', 'PRATO' => 'PO', 'RAGUSA' => 'RG',
    'RAVENNA' => 'RA', 'REGGIO CALABRIA' => 'RC', 'REGGIO EMILIA' => 'RE', 'RIETI' => 'RI',
    'RIMINI' => 'RN', 'ROMA' => 'RM', 'ROVIGO' => 'RO', 'SALERNO' => 'SA',
    'SASSARI' => 'SS', 'SAVONA' => 'SV', 'SIENA' => 'SI', 'SIRACUSA' => 'SR',
    'SONDRIO' => 'SO', 'SUD SARDEGNA' => 'SU', 'TARANTO' => 'TA', 'TERAMO' => 'TE',
    'TERNI' => 'TR', 'TORINO' => 'TO', 'TRAPANI' => 'TP', 'TRENTO' => 'TN',
    'TREVISO' => 'TV', 'TRIESTE' => 'TS', 'UDINE' => 'UD', 'VARESE' => 'VA',
    'VENEZIA' => 'VE', 'VERBANO-CUSIO-OSSOLA' => 'VB', 'VERCELLI' => 'VC', 'VERONA' => 'VR',
    'VIBO VALENTIA' => 'VV', 'VICENZA' => 'VI', 'VITERBO' => 'VT',
];

function normalizeProvincia(string $prov): string {
    $prov = strtoupper(trim($prov));
    if (strlen($prov) === 2) return $prov;
    return PROVINCE_MAP[$prov] ?? $prov;
}

$comune    = trim($_GET['comune'] ?? '');
$provincia = normalizeProvincia($_GET['provincia'] ?? '');
$mode      = trim($_GET['mode'] ?? 'fetch');

if (!$comune || !$provincia) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    sendError('Parametri comune e provincia obbligatori', 400);
}

if (!preg_match('/^[\p{L}\s\'\-]+$/u', $comune) || !preg_match('/^[A-Za-z]{2}$/', $provincia)) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    sendError('Parametri non validi', 400);
}

$cacheFile = getCacheFilePath($comune, $provincia);

if ($mode === 'check') {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    if (file_exists($cacheFile)) {
        $cacheRaw = file_get_contents($cacheFile);
        $cache = json_decode($cacheRaw, true);
        if (!is_array($cache)) {
            sendSuccess(['cached' => false]);
        }

        $cacheDate = new DateTime($cache['cache_date'] ?? 'now');
        $now = new DateTime();
        $ageDays = (int)$now->diff($cacheDate)->days;

        sendSuccess([
            'ok'              => true,
            'cached'          => true,
            'cache_date'      => $cache['cache_date'] ?? '',
            'cache_age_days'  => $ageDays,
            'needs_update'    => $ageDays > CACHE_MAX_DAYS,
            'particelle_count' => count($cache['particelle'] ?? []),
            'comune'          => $cache['comune'] ?? $comune,
            'provincia'       => $cache['provincia'] ?? $provincia,
        ]);
    } else {
        sendSuccess(['ok' => true, 'cached' => false]);
    }
}

if ($mode === 'fetch') {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');

    if (file_exists($cacheFile)) {
        $cacheRaw = file_get_contents($cacheFile);
        $cache = json_decode($cacheRaw, true);
        if (is_array($cache)) {
            $cacheDate = new DateTime($cache['cache_date'] ?? 'now');
            $now = new DateTime();
            $ageDays = (int)$now->diff($cacheDate)->days;
            if ($ageDays <= CACHE_MAX_DAYS) {
                $cache['cache_age_days'] = $ageDays;
                sendSuccess($cache);
            }
        }
    }

    try {
        $result = fetchParticelleWFS($comune, $provincia);
        saveCache($cacheFile, $result);
        sendSuccess($result);
    } catch (Exception $e) {
        sendError($e->getMessage(), 500);
    }
}

if ($mode === 'stream') {
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    header('Access-Control-Allow-Origin: *');
    streamFetchParticelleWFS($comune, $provincia, $cacheFile);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
sendError('Mode non valido', 400);

function fetchParticelleWFS(string $comune, string $provincia): array {
    $startTime = time();
    $comuneCoords = geocodeComune($comune, $provincia);
    if (!$comuneCoords) {
        throw new Exception("Impossibile geocodificare il comune: $comune ($provincia)");
    }

    $bbox = createBoundingBox($comuneCoords['lat'], $comuneCoords['lng']);
    $tiles = createTiles($bbox, TILE_SIZE);
    $particelle = [];

    foreach ($tiles as $tile) {
        $features = queryWFSTile($tile);
        foreach ($features as $feature) {
            $key = "{$feature['cod_comune']}|{$feature['foglio']}|{$feature['particella']}";
            if (!isset($particelle[$key])) {
                $particelle[$key] = $feature;
            }
        }
        usleep(TILE_DELAY_US);
    }

    return [
        'ok'               => true,
        'comune'           => $comune,
        'provincia'        => $provincia,
        'cache_date'       => date('c'),
        'cache_age_days'   => 0,
        'tiles_queried'    => count($tiles),
        'particelle_found' => count($particelle),
        'fetch_duration_sec' => time() - $startTime,
        'particelle'       => $particelle,
    ];
}

function streamFetchParticelleWFS(string $comune, string $provincia, string $cacheFile): void {
    $startTime = time();
    $comuneCoords = geocodeComune($comune, $provincia);
    if (!$comuneCoords) {
        echo "data: " . json_encode(['error' => 'Geocodifica fallita']) . "\n\n";
        flush();
        return;
    }

    $bbox = createBoundingBox($comuneCoords['lat'], $comuneCoords['lng']);
    $tiles = createTiles($bbox, TILE_SIZE);
    $total = count($tiles);
    $particelle = [];
    $tilesQueried = 0;

    foreach ($tiles as $tile) {
        $features = queryWFSTile($tile);
        foreach ($features as $feature) {
            $key = "{$feature['cod_comune']}|{$feature['foglio']}|{$feature['particella']}";
            if (!isset($particelle[$key])) {
                $particelle[$key] = $feature;
            }
        }

        $tilesQueried++;
        echo "data: " . json_encode([
            'tiles_queried' => $tilesQueried,
            'total_tiles'   => $total,
            'found'         => count($particelle),
            'percent'       => $total > 0 ? round(($tilesQueried / $total) * 100, 1) : 0,
        ]) . "\n\n";
        flush();

        usleep(TILE_DELAY_US);
    }

    $result = [
        'ok'               => true,
        'comune'           => $comune,
        'provincia'        => $provincia,
        'cache_date'       => date('c'),
        'cache_age_days'   => 0,
        'tiles_queried'    => $tilesQueried,
        'particelle_found' => count($particelle),
        'fetch_duration_sec' => time() - $startTime,
        'particelle'       => $particelle,
    ];

    saveCache($cacheFile, $result);
    echo "data: " . json_encode(['complete' => true, 'result' => $result]) . "\n\n";
    flush();
}

function queryWFSTile(array $tile): array {
    $xml = buildWFSRequest($tile);
    $ch = curl_init(WFS_ENDPOINT);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $xml,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => WFS_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'EasyCatasto-Analytics/1.0',
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/xml',
            'Accept: application/gml+xml, text/xml;q=0.9, */*;q=0.1',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err || $httpCode !== 200 || !$response) {
        return [];
    }

    return parseGMLResponse($response);
}

function buildWFSRequest(array $tile): string {
    $minLng = $tile['minLng'];
    $minLat = $tile['minLat'];
    $maxLng = $tile['maxLng'];
    $maxLat = $tile['maxLat'];

    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<wfs:GetFeature service="WFS" version="2.0.0"
  xmlns:wfs="http://www.opengis.net/wfs/2.0"
  xmlns:fes="http://www.opengis.net/fes/2.0"
  xmlns:gml="http://www.opengis.net/gml/3.2"
  xmlns:cp="http://inspire.ec.europa.eu/schemas/cp/4.0">
  <wfs:Query typeNames="CP.CadastralParcel">
    <fes:Filter>
      <fes:BBOX>
        <fes:ValueReference>geometry</fes:ValueReference>
        <gml:Envelope srsName="EPSG:4326">
          <gml:lowerCorner>{$minLng} {$minLat}</gml:lowerCorner>
          <gml:upperCorner>{$maxLng} {$maxLat}</gml:upperCorner>
        </gml:Envelope>
      </fes:BBOX>
    </fes:Filter>
  </wfs:Query>
</wfs:GetFeature>
XML;
}

function parseGMLResponse(string $gml): array {
    $doc = new DOMDocument();
    if (!@$doc->loadXML($gml)) return [];

    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('wfs', 'http://www.opengis.net/wfs/2.0');
    $xpath->registerNamespace('cp', 'http://inspire.ec.europa.eu/schemas/cp/4.0');
    $xpath->registerNamespace('gml', 'http://www.opengis.net/gml/3.2');

    $features = [];
    $members = $xpath->query('//wfs:member');
    foreach ($members as $member) {
        $feature = parseFeatureMember($xpath, $member);
        if ($feature) $features[] = $feature;
    }
    return $features;
}

function parseFeatureMember(DOMXPath $xpath, DOMNode $member): ?array {
    $ncrNodes = $xpath->query('.//cp:nationalCadastralReference', $member);
    if ($ncrNodes->length === 0) return null;

    $ncr = trim($ncrNodes->item(0)->textContent);
    $parts = explode('.', $ncr);
    if (count($parts) < 3) return null;

    $codComune = trim($parts[0]);
    $foglioRaw = trim($parts[1]);
    $particellaRaw = trim($parts[2]);
    $foglio = ltrim($foglioRaw, '0');
    $particella = ltrim($particellaRaw, '0');
    if ($foglio === '') $foglio = $foglioRaw;
    if ($particella === '') $particella = $particellaRaw;

    $coords = [];
    $geomNodes = $xpath->query('.//gml:posList | .//gml:pos', $member);
    foreach ($geomNodes as $node) {
        $posText = trim($node->textContent);
        if ($posText === '') continue;
        $values = preg_split('/\s+/', $posText);
        $len = count($values);
        for ($i = 0; $i + 1 < $len; $i += 2) {
            $coords[] = [(float)$values[$i], (float)$values[$i + 1]];
        }
    }

    // Un poligono valido richiede almeno 3 vertici.
    if (count($coords) < 3) return null;
    $centroid = calculateCentroid($coords);

    return [
        'cod_comune' => $codComune,
        'foglio'     => $foglio,
        'particella' => $particella,
        'lat'        => $centroid['lat'],
        'lng'        => $centroid['lng'],
        'polygon'    => $coords,
        'area_mq'    => calculateArea($coords),
    ];
}

function calculateCentroid(array $coords): array {
    $sumLat = 0.0;
    $sumLng = 0.0;
    $count = count($coords);
    foreach ($coords as $coord) {
        $sumLng += $coord[0];
        $sumLat += $coord[1];
    }
    return [
        'lat' => $sumLat / $count,
        'lng' => $sumLng / $count,
    ];
}

function calculateArea(array $coords): float {
    $area = 0.0;
    $n = count($coords);
    for ($i = 0; $i < $n; $i++) {
        $j = ($i + 1) % $n;
        $area += $coords[$i][0] * $coords[$j][1];
        $area -= $coords[$j][0] * $coords[$i][1];
    }
    $area = abs($area) / 2;
    return $area * METERS_PER_DEGREE * METERS_PER_DEGREE;
}

function geocodeComune(string $comune, string $provincia): ?array {
    $query = urlencode("$comune, $provincia, Italia");
    $url = "https://nominatim.openstreetmap.org/search?q=$query&format=json&limit=1";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => 'EasyCatasto-Analytics/1.0',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);

    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err || !$response) return null;

    $data = json_decode($response, true);
    if (is_array($data) && isset($data[0]['lat'], $data[0]['lon'])) {
        return ['lat' => (float)$data[0]['lat'], 'lng' => (float)$data[0]['lon']];
    }
    return null;
}

function createBoundingBox(float $lat, float $lng): array {
    return [
        'minLat' => $lat - BBOX_RADIUS,
        'maxLat' => $lat + BBOX_RADIUS,
        'minLng' => $lng - BBOX_RADIUS,
        'maxLng' => $lng + BBOX_RADIUS,
    ];
}

function createTiles(array $bbox, float $tileSize): array {
    $tiles = [];
    $lngSteps = (int)ceil(($bbox['maxLng'] - $bbox['minLng']) / $tileSize);
    $latSteps = (int)ceil(($bbox['maxLat'] - $bbox['minLat']) / $tileSize);

    for ($i = 0; $i < $lngSteps; $i++) {
        for ($j = 0; $j < $latSteps; $j++) {
            $tiles[] = [
                'minLng' => $bbox['minLng'] + ($i * $tileSize),
                'maxLng' => min($bbox['minLng'] + (($i + 1) * $tileSize), $bbox['maxLng']),
                'minLat' => $bbox['minLat'] + ($j * $tileSize),
                'maxLat' => min($bbox['minLat'] + (($j + 1) * $tileSize), $bbox['maxLat']),
            ];
        }
    }

    return $tiles;
}

function getCacheFilePath(string $comune, string $provincia): string {
    $provinciaNorm = normalizeProvincia($provincia);
    $filename = strtoupper($comune) . '_' . strtoupper($provinciaNorm) . '_wfs.json';
    $filename = basename($filename);
    $filename = preg_replace('/[^A-Z0-9_\.]/i', '_', $filename);
    return CACHE_DIR . '/' . $filename;
}

function saveCache(string $filePath, array $data): void {
    if (!is_dir(CACHE_DIR)) {
        mkdir(CACHE_DIR, 0755, true);
    }
    file_put_contents($filePath, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function sendSuccess(array $data): void {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function sendError(string $message, int $code = 500): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}
