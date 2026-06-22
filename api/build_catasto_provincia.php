<?php
declare(strict_types=1);

date_default_timezone_set('Europe/Rome');
set_time_limit(0);

define('DB_PATH', __DIR__ . '/../cache/catasto/catasto_italia.db');
define('DOWNLOAD_DIR', __DIR__ . '/../cache/catasto/downloads');
define('ADE_DOWNLOAD_URL', 'https://wfs.cartografia.agenziaentrate.gov.it/inspire/wfs/GetDataset.php?dataset=');
define('METERS_PER_DEGREE', 111000);
define('SQLITE_BUSY_TIMEOUT_MS', 10000);
define('CENTROID_AREA_EPSILON', 1e-12);

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    handleRequest();
}

function handleRequest(): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    enforceAdminAccess(true);

    $action = strtolower(trim((string)($_REQUEST['action'] ?? 'stats')));

    try {
        switch ($action) {
            case 'build':
                $provincia = normalizeProvincia((string)($_REQUEST['provincia'] ?? ''));
                if ($provincia === '') {
                    sendError('Provincia obbligatoria', 400);
                }
                sendJson(buildProvincia($provincia));
                break;

            case 'stats':
                sendJson(getDatabaseStats());
                break;

            case 'clear':
                sendJson(clearDatabase());
                break;

            default:
                sendError('Action non valida', 400);
        }
    } catch (Throwable $e) {
        sendError($e->getMessage(), 500);
    }
}

function buildProvincia(string $provincia): array
{
    ensureCatastoDirectories();
    $start = microtime(true);
    $zipPath = DOWNLOAD_DIR . '/' . $provincia . '.zip';
    $extractDir = DOWNLOAD_DIR . '/' . $provincia . '_' . date('Ymd_His');

    $db = openDatabase();

    try {
        downloadProvinciaDataset($provincia, $zipPath);
        extractZipArchive($zipPath, $extractDir);

        $gmlFiles = findGmlFiles($extractDir);
        if (!$gmlFiles) {
            throw new RuntimeException("Nessun file GML trovato per la provincia $provincia");
        }

        $inserted = 0;
        $parsed = 0;
        foreach ($gmlFiles as $gmlFile) {
            $particelle = parseGMLFile($gmlFile, $provincia);
            $parsed += count($particelle);
            if ($particelle) {
                $inserted += insertParticelle($db, $particelle);
            }
        }

        $stats = getDatabaseStats($db);

        return [
            'ok' => true,
            'provincia' => $provincia,
            'gml_files' => count($gmlFiles),
            'particelle_parsed' => $parsed,
            'particelle_imported' => $inserted,
            'duration_sec' => round(microtime(true) - $start, 2),
            'db_size_mb' => $stats['db_size_mb'],
            'total_particelle' => $stats['total_particelle'],
            'province_count' => $stats['province_count'],
        ];
    } finally {
        $db->close();
        cleanupPath($zipPath);
        cleanupPath($extractDir);
    }
}

function getDatabaseStats(?SQLite3 $db = null): array
{
    $ownsConnection = false;
    if ($db === null && file_exists(DB_PATH)) {
        $db = openDatabase();
        $ownsConnection = true;
    }

    try {
        $totalParticelle = $db ? (int)$db->querySingle('SELECT COUNT(*) FROM particelle') : 0;
        $provinceCount = $db ? (int)$db->querySingle('SELECT COUNT(DISTINCT provincia) FROM particelle') : 0;
        $dbSize = file_exists(DB_PATH) ? filesize(DB_PATH) : 0;

        return [
            'ok' => true,
            'total_particelle' => $totalParticelle,
            'province_count' => $provinceCount,
            'db_size_mb' => round($dbSize / 1048576, 2),
        ];
    } finally {
        if ($ownsConnection && $db instanceof SQLite3) {
            $db->close();
        }
    }
}

function clearDatabase(): array
{
    cleanupPath(DB_PATH);
    cleanupPath(DB_PATH . '-wal');
    cleanupPath(DB_PATH . '-shm');
    cleanupPath(DOWNLOAD_DIR);
    ensureCatastoDirectories();
    ensurePlaceholderFiles();

    return [
        'ok' => true,
        'message' => 'Database catasto eliminato',
        'total_particelle' => 0,
        'province_count' => 0,
        'db_size_mb' => 0,
    ];
}

function ensureCatastoDirectories(): void
{
    $catastoDir = dirname(DB_PATH);
    if (!is_dir($catastoDir) && !mkdir($catastoDir, 0755, true) && !is_dir($catastoDir)) {
        throw new RuntimeException('Impossibile creare directory cache/catasto');
    }

    if (!is_dir(DOWNLOAD_DIR) && !mkdir(DOWNLOAD_DIR, 0755, true) && !is_dir(DOWNLOAD_DIR)) {
        throw new RuntimeException('Impossibile creare directory download catasto');
    }

    ensurePlaceholderFiles();
}

function ensurePlaceholderFiles(): void
{
    $placeholders = [
        dirname(DB_PATH) . '/.gitkeep' => "# Database Catasto Italia\n# Generato da admin_build_catasto.php\n",
        DOWNLOAD_DIR . '/.gitkeep' => "# Download temporanei catasto\n",
    ];

    foreach ($placeholders as $path => $content) {
        if (!file_exists($path)) {
            file_put_contents($path, $content);
        }
    }
}

function enforceAdminAccess(bool $jsonResponse = false): void
{
    $configuredToken = (string)(getenv('CATASTO_BUILD_TOKEN') ?: '');
    $providedToken = (string)($_SERVER['HTTP_X_CATASTO_ADMIN_TOKEN'] ?? $_REQUEST['token'] ?? '');

    if ($configuredToken !== '') {
        if (!hash_equals($configuredToken, $providedToken)) {
            denyAdminAccess($jsonResponse);
        }
        return;
    }

    $remoteAddr = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    if (!isPrivateOrLocalAddress($remoteAddr)) {
        denyAdminAccess($jsonResponse);
    }
}

function isPrivateOrLocalAddress(string $ip): bool
{
    if ($ip === '' || $ip === '127.0.0.1' || $ip === '::1') {
        return true;
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        return preg_match('/^(10\.|192\.168\.|172\.(1[6-9]|2\d|3[0-1])\.)/', $ip) === 1;
    }

    return str_starts_with(strtolower($ip), 'fc') || str_starts_with(strtolower($ip), 'fd');
}

function denyAdminAccess(bool $jsonResponse): void
{
    if ($jsonResponse) {
        sendError('Accesso negato: endpoint admin disponibile solo da rete locale o con token CATASTO_BUILD_TOKEN', 403);
    }

    http_response_code(403);
    exit('Accesso negato');
}

function openDatabase(): SQLite3
{
    ensureCatastoDirectories();

    $db = new SQLite3(DB_PATH);
    $db->busyTimeout(SQLITE_BUSY_TIMEOUT_MS);
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec('PRAGMA synchronous = NORMAL');
    $db->exec('PRAGMA temp_store = MEMORY');
    $db->exec('PRAGMA foreign_keys = ON');

    $db->exec('
        CREATE TABLE IF NOT EXISTS particelle (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            cod_comune TEXT NOT NULL,
            comune TEXT,
            provincia TEXT NOT NULL,
            foglio TEXT NOT NULL,
            particella TEXT NOT NULL,
            subalterno TEXT,
            lat REAL NOT NULL,
            lng REAL NOT NULL,
            polygon TEXT,
            area_mq REAL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP
        )
    ');
    $db->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_particella ON particelle(cod_comune, foglio, particella)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_provincia ON particelle(provincia)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_coords ON particelle(lat, lng)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_comune_lookup ON particelle(comune, provincia, foglio, particella)');

    return $db;
}

function downloadProvinciaDataset(string $provincia, string $targetPath): void
{
    $url = ADE_DOWNLOAD_URL . rawurlencode($provincia);
    $handle = fopen($targetPath, 'wb');
    if ($handle === false) {
        throw new RuntimeException("Impossibile creare file ZIP per $provincia");
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_FILE => $handle,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_USERAGENT => 'EasyCatasto Analytics/1.0 (+https://github.com/simonedeitos/analytics)',
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FAILONERROR => false,
        CURLOPT_HTTPHEADER => ['Accept: application/zip, application/octet-stream;q=0.9, */*;q=0.1'],
    ]);

    $result = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    fclose($handle);

    if ($result === false || $curlError !== '' || $httpCode >= 400 || !file_exists($targetPath) || filesize($targetPath) === 0) {
        cleanupPath($targetPath);
        $reason = $curlError !== '' ? $curlError : "HTTP $httpCode";
        throw new RuntimeException("Download fallito per $provincia: $reason");
    }
}

function extractZipArchive(string $zipPath, string $extractDir): void
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('Impossibile aprire archivio ZIP');
    }

    if (!mkdir($extractDir, 0755, true) && !is_dir($extractDir)) {
        $zip->close();
        throw new RuntimeException('Impossibile creare directory estrazione ZIP');
    }

    if (!$zip->extractTo($extractDir)) {
        $zip->close();
        throw new RuntimeException('Impossibile estrarre archivio ZIP');
    }

    $zip->close();
}

function findGmlFiles(string $directory): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }

        $path = $fileInfo->getPathname();
        if (stripos($path, 'CadastralParcel.gml') !== false) {
            $files[] = $path;
        }
    }

    if ($files) {
        sort($files);
        return $files;
    }

    $fallbackIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($fallbackIterator as $fileInfo) {
        if ($fileInfo->isFile() && strtolower($fileInfo->getExtension()) === 'gml') {
            $files[] = $fileInfo->getPathname();
        }
    }

    sort($files);
    return $files;
}

function parseGMLFile(string $filePath, string $provincia): array
{
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $loaded = $doc->load($filePath, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_clear_errors();
    libxml_use_internal_errors(false);

    if (!$loaded) {
        return [];
    }

    $xpath = new DOMXPath($doc);
    $xpath->registerNamespace('wfs', 'http://www.opengis.net/wfs/2.0');
    $xpath->registerNamespace('gml', 'http://www.opengis.net/gml/3.2');
    $xpath->registerNamespace('cp', 'http://inspire.ec.europa.eu/schemas/cp/4.0');
    $xpath->registerNamespace('base', 'http://inspire.ec.europa.eu/schemas/base/3.3');
    $xpath->registerNamespace('gn', 'http://inspire.ec.europa.eu/schemas/gn/4.0');

    $members = $xpath->query('//wfs:member | //gml:featureMember');
    if ($members === false || $members->length === 0) {
        return [];
    }

    $fallbackComune = deriveComuneFromPath($filePath);
    $particelle = [];

    foreach ($members as $member) {
        $particella = parseFeatureMember($member, $xpath, $provincia, $fallbackComune);
        if ($particella !== null) {
            $particelle[] = $particella;
        }
    }

    return $particelle;
}

function parseFeatureMember(DOMNode $member, DOMXPath $xpath, string $provincia, string $fallbackComune): ?array
{
    $ncrNodes = $xpath->query('.//cp:nationalCadastralReference | .//*[local-name()="nationalCadastralReference"]', $member);
    if ($ncrNodes === false || $ncrNodes->length === 0) {
        return null;
    }

    $ncr = trim($ncrNodes->item(0)->textContent);
    $parts = array_map('trim', explode('.', $ncr));
    if (count($parts) < 3) {
        return null;
    }

    $codComune = $parts[0];
    $foglio = normalizeNumericCode($parts[1]);
    $particella = normalizeNumericCode($parts[2]);
    $subalterno = isset($parts[3]) ? normalizeNumericCode($parts[3]) : null;

    $coords = [];
    $geomNodes = $xpath->query('.//gml:posList | .//gml:pos', $member);
    if ($geomNodes === false) {
        return null;
    }

    foreach ($geomNodes as $node) {
        $srsName = resolveSrsName($node);
        foreach (extractCoordinates((string)$node->textContent, $srsName) as $coord) {
            $coords[] = $coord;
        }
    }

    if (count($coords) < 3) {
        return null;
    }

    $centroid = calculateCentroid($coords);
    $area = calculatePolygonArea($coords);
    $comune = extractComuneName($member, $xpath, $fallbackComune);

    return [
        'cod_comune' => $codComune,
        'comune' => $comune,
        'provincia' => $provincia,
        'foglio' => $foglio,
        'particella' => $particella,
        'subalterno' => $subalterno,
        'lat' => $centroid['lat'],
        'lng' => $centroid['lng'],
        'polygon' => json_encode($coords, JSON_UNESCAPED_UNICODE),
        'area_mq' => $area,
    ];
}

function normalizeNumericCode(string $value): string
{
    $value = preg_replace('/\D+/', '', $value);
    $value = ltrim($value, '0');
    return $value === '' ? '0' : $value;
}

function resolveSrsName(DOMNode $node): string
{
    $current = $node;
    while ($current instanceof DOMElement) {
        if ($current->hasAttribute('srsName')) {
            return (string)$current->getAttribute('srsName');
        }
        $current = $current->parentNode;
    }

    return '';
}

function extractCoordinates(string $text, string $srsName): array
{
    $values = preg_split('/\s+/', trim($text)) ?: [];
    $coords = [];

    for ($i = 0, $len = count($values); $i + 1 < $len; $i += 2) {
        $first = (float)$values[$i];
        $second = (float)$values[$i + 1];
        $coords[] = normalizeCoordinatePair($first, $second, $srsName);
    }

    return $coords;
}

function normalizeCoordinatePair(float $first, float $second, string $srsName): array
{
    $srsName = strtoupper($srsName);

    if (str_contains($srsName, 'CRS84')) {
        return ['lat' => $second, 'lng' => $first];
    }

    if ($first >= 35 && $first <= 48 && $second >= 6 && $second <= 19) {
        return ['lat' => $first, 'lng' => $second];
    }

    if ($second >= 35 && $second <= 48 && $first >= 6 && $first <= 19) {
        return ['lat' => $second, 'lng' => $first];
    }

    if (str_contains($srsName, '4326')) {
        return ['lat' => $first, 'lng' => $second];
    }

    return ['lat' => $first, 'lng' => $second];
}

function extractComuneName(DOMNode $member, DOMXPath $xpath, string $fallbackComune): ?string
{
    $queries = [
        './/cp:label',
        './/*[local-name()="adminUnitName"]',
        './/*[local-name()="comune"]',
        './/*[local-name()="municipality"]',
        './/*[local-name()="name"]',
        './/gn:text',
    ];

    foreach ($queries as $query) {
        $nodes = $xpath->query($query, $member);
        if ($nodes === false || $nodes->length === 0) {
            continue;
        }

        $value = normalizeComuneLabel((string)$nodes->item(0)->textContent);
        if ($value !== '') {
            return $value;
        }
    }

    return $fallbackComune !== '' ? $fallbackComune : null;
}

function normalizeComuneLabel(string $value): string
{
    $value = strtoupper(trim($value));
    $value = preg_replace('/[_\-]+/', ' ', $value) ?? '';
    $value = preg_replace('/\s+/', ' ', $value) ?? '';
    if ($value === '' || str_contains($value, 'CADASTRALPARCEL')) {
        return '';
    }
    return $value;
}

function deriveComuneFromPath(string $filePath): string
{
    $segments = array_reverse(explode(DIRECTORY_SEPARATOR, dirname($filePath)));
    foreach ($segments as $segment) {
        $segment = normalizeComuneLabel($segment);
        if ($segment !== '' && !str_contains($segment, 'DOWNLOADS') && !preg_match('/^\d+$/', $segment)) {
            return $segment;
        }
    }

    return '';
}

function calculateCentroid(array $coords): array
{
    $area2 = 0.0;
    $sumLat = 0.0;
    $sumLng = 0.0;
    $n = count($coords);

    for ($i = 0; $i < $n; $i++) {
        $j = ($i + 1) % $n;
        $cross = ($coords[$i]['lng'] * $coords[$j]['lat']) - ($coords[$j]['lng'] * $coords[$i]['lat']);
        $area2 += $cross;
        $sumLng += ($coords[$i]['lng'] + $coords[$j]['lng']) * $cross;
        $sumLat += ($coords[$i]['lat'] + $coords[$j]['lat']) * $cross;
    }

    if (abs($area2) < CENTROID_AREA_EPSILON) {
        $lat = 0.0;
        $lng = 0.0;
        foreach ($coords as $coord) {
            $lat += $coord['lat'];
            $lng += $coord['lng'];
        }

        return [
            'lat' => $lat / $n,
            'lng' => $lng / $n,
        ];
    }

    $factor = 1 / (3 * $area2);

    return [
        'lat' => $sumLat * $factor,
        'lng' => $sumLng * $factor,
    ];
}

function calculatePolygonArea(array $coords): float
{
    $latRef = 0.0;
    foreach ($coords as $coord) {
        $latRef += $coord['lat'];
    }
    $latRef /= count($coords);
    $metersPerDegreeLng = METERS_PER_DEGREE * cos(deg2rad($latRef));

    $area = 0.0;
    $n = count($coords);
    // Local planar approximation: sufficient for small cadastral parcels,
    // less accurate for very large geometries or extreme latitudes.
    for ($i = 0; $i < $n; $i++) {
        $j = ($i + 1) % $n;
        $x1 = $coords[$i]['lng'] * $metersPerDegreeLng;
        $y1 = $coords[$i]['lat'] * METERS_PER_DEGREE;
        $x2 = $coords[$j]['lng'] * $metersPerDegreeLng;
        $y2 = $coords[$j]['lat'] * METERS_PER_DEGREE;
        $area += ($x1 * $y2) - ($x2 * $y1);
    }

    return abs($area) / 2;
}

function insertParticelle(SQLite3 $db, array $particelle): int
{
    $db->exec('BEGIN TRANSACTION');
    $stmt = $db->prepare('
        INSERT OR IGNORE INTO particelle
        (cod_comune, comune, provincia, foglio, particella, subalterno, lat, lng, polygon, area_mq)
        VALUES (:cod_comune, :comune, :provincia, :foglio, :particella, :subalterno, :lat, :lng, :polygon, :area_mq)
    ');

    if ($stmt === false) {
        $db->exec('ROLLBACK');
        throw new RuntimeException('Impossibile preparare query di inserimento');
    }

    $inserted = 0;

    try {
        foreach ($particelle as $particella) {
            $stmt->reset();
            $stmt->clear();
            $stmt->bindValue(':cod_comune', $particella['cod_comune'], SQLITE3_TEXT);
            $stmt->bindValue(':comune', $particella['comune'], SQLITE3_TEXT);
            $stmt->bindValue(':provincia', $particella['provincia'], SQLITE3_TEXT);
            $stmt->bindValue(':foglio', $particella['foglio'], SQLITE3_TEXT);
            $stmt->bindValue(':particella', $particella['particella'], SQLITE3_TEXT);
            $stmt->bindValue(':subalterno', $particella['subalterno'], $particella['subalterno'] === null ? SQLITE3_NULL : SQLITE3_TEXT);
            $stmt->bindValue(':lat', $particella['lat'], SQLITE3_FLOAT);
            $stmt->bindValue(':lng', $particella['lng'], SQLITE3_FLOAT);
            $stmt->bindValue(':polygon', $particella['polygon'], SQLITE3_TEXT);
            $stmt->bindValue(':area_mq', $particella['area_mq'], SQLITE3_FLOAT);
            $result = $stmt->execute();
            if ($result instanceof SQLite3Result) {
                $result->finalize();
            }

            if ($db->changes() > 0) {
                $inserted++;
            }
        }

        $db->exec('COMMIT');
    } catch (Throwable $e) {
        $db->exec('ROLLBACK');
        throw $e;
    } finally {
        $stmt->close();
    }

    return $inserted;
}

function cleanupPath(string $path): void
{
    if (!file_exists($path)) {
        return;
    }

    if (is_file($path) || is_link($path)) {
        @unlink($path);
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    foreach ($iterator as $item) {
        if ($item->isDir()) {
            @rmdir($item->getPathname());
        } else {
            @unlink($item->getPathname());
        }
    }

    @rmdir($path);
}

function normalizeProvincia(string $provincia): string
{
    $provincia = strtoupper(trim($provincia));
    $provincia = preg_replace('/\s+/', '-', $provincia) ?? '';
    return preg_replace('/[^A-Z\-]/', '', $provincia) ?? '';
}

function sendJson(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function sendError(string $message, int $status = 500): void
{
    http_response_code($status);
    sendJson(['ok' => false, 'error' => $message]);
}
