<?php
/**
 * api/scan_particelle.php
 * Endpoint per scan preciso delle particelle catastali tramite API Agenzia delle Entrate.
 * Supporta cache server-side persistente e progress tracking real-time via SSE.
 */
date_default_timezone_set('Europe/Rome');

define('CACHE_DIR', __DIR__ . '/../cache/particelle');
define('CACHE_MAX_DAYS', 30);
define('AE_AJAX_URL', 'https://wms.cartografia.agenziaentrate.gov.it/inspire/ajax/ajax.php');
define('GRID_SIZE', 25);
define('BBOX_RADIUS', 0.03); // ~3km
define('RATE_LIMIT_MS', 1000); // 1 req/sec

$comune   = trim($_GET['comune']   ?? '');
$provincia = trim($_GET['provincia'] ?? '');
$mode     = trim($_GET['mode']     ?? 'scan');

if (!$comune || !$provincia) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    sendError('Parametri comune e provincia obbligatori', 400);
}

// Validate inputs: only letters, spaces, hyphens and apostrophes allowed
if (!preg_match('/^[\p{L}\s\'\-]+$/u', $comune) || !preg_match('/^[A-Za-z]{2}$/', $provincia)) {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    sendError('Parametri non validi', 400);
}

$cacheFile = getCacheFilePath($comune, $provincia);

// MODE: check
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
            'cached'          => true,
            'cache_date'      => $cache['cache_date'] ?? '',
            'cache_age_days'  => $ageDays,
            'needs_update'    => $ageDays > CACHE_MAX_DAYS,
            'particelle_count' => count($cache['particelle'] ?? []),
            'comune'          => $cache['comune'] ?? $comune,
            'provincia'       => $cache['provincia'] ?? $provincia,
        ]);
    } else {
        sendSuccess(['cached' => false]);
    }
}

// MODE: scan
if ($mode === 'scan') {
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    // Return from cache if fresh
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
        $result = scanComune($comune, $provincia);
        saveCache($cacheFile, $result);
        sendSuccess($result);
    } catch (Exception $e) {
        sendError($e->getMessage(), 500);
    }
}

// MODE: stream (Server-Sent Events)
if ($mode === 'stream') {
    // Disable output buffering
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    header('Access-Control-Allow-Origin: *');
    streamScanComune($comune, $provincia, $cacheFile);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
sendError('Mode non valido', 400);

// ---- Functions ----

function scanComune(string $comune, string $provincia): array {
    $startTime = time();

    $comuneCoords = geocodeComune($comune, $provincia);
    if (!$comuneCoords) {
        throw new Exception("Impossibile geocodificare il comune: $comune ($provincia)");
    }

    $bbox       = createBoundingBox($comuneCoords['lat'], $comuneCoords['lng']);
    $gridPoints = createGrid($bbox, GRID_SIZE);

    $particelle = [];
    $scanned    = 0;

    foreach ($gridPoints as $point) {
        $data = callAdEApi($point['lat'], $point['lng']);

        if (
            is_array($data) &&
            !empty($data['FOGLIO']) &&
            !empty($data['NUM_PART'])
        ) {
            $foglio     = ltrim($data['FOGLIO'], '0') ?: $data['FOGLIO'];
            $particella = ltrim($data['NUM_PART'], '0') ?: $data['NUM_PART'];
            $codComune  = $data['COD_COMUNE'] ?? '';
            $key        = "$codComune|$foglio|$particella";

            if (!isset($particelle[$key])) {
                $particelle[$key] = [
                    'lat'        => $point['lat'],
                    'lng'        => $point['lng'],
                    'foglio'     => $foglio,
                    'particella' => $particella,
                    'cod_comune' => $codComune,
                    'sezione'    => $data['SEZIONE']  ?? '',
                    'sviluppo'   => $data['SVILUPPO'] ?? '',
                ];
            }
        }

        $scanned++;
        usleep(RATE_LIMIT_MS * 1000);
    }

    $duration = time() - $startTime;
    $firstParticella = reset($particelle);

    return [
        'ok'               => true,
        'comune'           => $comune,
        'provincia'        => $provincia,
        'cod_comune'       => $firstParticella ? $firstParticella['cod_comune'] : '',
        'cache_date'       => date('c'),
        'cache_age_days'   => 0,
        'points_scanned'   => $scanned,
        'particelle_found' => count($particelle),
        'scan_duration_sec' => $duration,
        'particelle'       => $particelle,
    ];
}

function streamScanComune(string $comune, string $provincia, string $cacheFile): void {
    $comuneCoords = geocodeComune($comune, $provincia);
    if (!$comuneCoords) {
        echo "data: " . json_encode(['error' => 'Geocodifica fallita']) . "\n\n";
        flush();
        return;
    }

    $bbox       = createBoundingBox($comuneCoords['lat'], $comuneCoords['lng']);
    $gridPoints = createGrid($bbox, GRID_SIZE);
    $total      = count($gridPoints);

    $particelle = [];
    $scanned    = 0;

    foreach ($gridPoints as $point) {
        $data = callAdEApi($point['lat'], $point['lng']);

        if (
            is_array($data) &&
            !empty($data['FOGLIO']) &&
            !empty($data['NUM_PART'])
        ) {
            $foglio     = ltrim($data['FOGLIO'], '0') ?: $data['FOGLIO'];
            $particella = ltrim($data['NUM_PART'], '0') ?: $data['NUM_PART'];
            $codComune  = $data['COD_COMUNE'] ?? '';
            $key        = "$codComune|$foglio|$particella";

            if (!isset($particelle[$key])) {
                $particelle[$key] = [
                    'lat'        => $point['lat'],
                    'lng'        => $point['lng'],
                    'foglio'     => $foglio,
                    'particella' => $particella,
                    'cod_comune' => $codComune,
                ];
            }
        }

        $scanned++;

        if ($scanned % 10 === 0 || $scanned === $total) {
            echo "data: " . json_encode([
                'scanned' => $scanned,
                'total'   => $total,
                'found'   => count($particelle),
                'percent' => round(($scanned / $total) * 100, 1),
            ]) . "\n\n";
            flush();
        }

        usleep(RATE_LIMIT_MS * 1000);
    }

    $firstParticella = reset($particelle);
    $result = [
        'ok'               => true,
        'comune'           => $comune,
        'provincia'        => $provincia,
        'cod_comune'       => $firstParticella ? $firstParticella['cod_comune'] : '',
        'cache_date'       => date('c'),
        'cache_age_days'   => 0,
        'points_scanned'   => $scanned,
        'particelle_found' => count($particelle),
        'particelle'       => $particelle,
    ];

    saveCache($cacheFile, $result);

    echo "data: " . json_encode(['complete' => true, 'result' => $result]) . "\n\n";
    flush();
}

function geocodeComune(string $comune, string $provincia): ?array {
    $query = urlencode("$comune, $provincia, Italia");
    $url   = "https://nominatim.openstreetmap.org/search?q=$query&format=json&limit=1";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_USERAGENT      => 'EasyCatasto-Analytics/1.0',
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
    ]);

    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err || !$response) return null;

    $data = json_decode($response, true);

    if (is_array($data) && isset($data[0]['lat'], $data[0]['lon'])) {
        return [
            'lat' => (float)$data[0]['lat'],
            'lng' => (float)$data[0]['lon'],
        ];
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

function createGrid(array $bbox, int $size): array {
    $points  = [];
    $latStep = ($bbox['maxLat'] - $bbox['minLat']) / $size;
    $lngStep = ($bbox['maxLng'] - $bbox['minLng']) / $size;

    for ($i = 0; $i < $size; $i++) {
        for ($j = 0; $j < $size; $j++) {
            $points[] = [
                'lat' => $bbox['minLat'] + ($i * $latStep) + ($latStep / 2),
                'lng' => $bbox['minLng'] + ($j * $lngStep) + ($lngStep / 2),
            ];
        }
    }

    return $points;
}

function callAdEApi(float $lat, float $lng): ?array {
    $url = AE_AJAX_URL . '?op=getDatiOggetto&lon=' . $lng . '&lat=' . $lat;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'EasyCatasto-Analytics/1.0',
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Referer: https://wms.cartografia.agenziaentrate.gov.it/',
        ],
    ]);

    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err || !$response) return null;

    $decoded = json_decode($response, true);
    return is_array($decoded) ? $decoded : null;
}

function getCacheFilePath(string $comune, string $provincia): string {
    $filename = strtoupper($comune) . '_' . strtoupper($provincia) . '.json';
    // Strip any path traversal characters and ensure only safe characters remain
    $filename = basename($filename);
    $filename = preg_replace('/[^A-Z0-9_\.]/i', '_', $filename);
    return CACHE_DIR . '/' . $filename;
}

function saveCache(string $filePath, array $data): void {
    if (!is_dir(CACHE_DIR)) {
        mkdir(CACHE_DIR, 0755, true);
    }
    file_put_contents(
        $filePath,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX
    );
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
