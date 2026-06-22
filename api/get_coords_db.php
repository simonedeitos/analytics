<?php
declare(strict_types=1);

define('DB_PATH', __DIR__ . '/../cache/catasto/catasto_italia.db');

const PROVINCE_MAP = [
    'AG' => 'AGRIGENTO', 'AL' => 'ALESSANDRIA', 'AN' => 'ANCONA', 'AO' => 'AOSTA',
    'AR' => 'AREZZO', 'AP' => 'ASCOLI-PICENO', 'AT' => 'ASTI', 'AV' => 'AVELLINO',
    'BA' => 'BARI', 'BT' => 'BARLETTA-ANDRIA-TRANI', 'BL' => 'BELLUNO', 'BN' => 'BENEVENTO',
    'BG' => 'BERGAMO', 'BI' => 'BIELLA', 'BO' => 'BOLOGNA', 'BZ' => 'BOLZANO',
    'BS' => 'BRESCIA', 'BR' => 'BRINDISI', 'CA' => 'CAGLIARI', 'CL' => 'CALTANISSETTA',
    'CB' => 'CAMPOBASSO', 'CE' => 'CASERTA', 'CT' => 'CATANIA', 'CZ' => 'CATANZARO',
    'CH' => 'CHIETI', 'CO' => 'COMO', 'CS' => 'COSENZA', 'CR' => 'CREMONA',
    'KR' => 'CROTONE', 'CN' => 'CUNEO', 'EN' => 'ENNA', 'FM' => 'FERMO',
    'FE' => 'FERRARA', 'FI' => 'FIRENZE', 'FG' => 'FOGGIA', 'FC' => 'FORLI-CESENA',
    'FR' => 'FROSINONE', 'GE' => 'GENOVA', 'GO' => 'GORIZIA', 'GR' => 'GROSSETO',
    'IM' => 'IMPERIA', 'IS' => 'ISERNIA', 'SP' => 'LA-SPEZIA', 'AQ' => 'LAQUILA',
    'LT' => 'LATINA', 'LE' => 'LECCE', 'LC' => 'LECCO', 'LI' => 'LIVORNO',
    'LO' => 'LODI', 'LU' => 'LUCCA', 'MC' => 'MACERATA', 'MN' => 'MANTOVA',
    'MS' => 'MASSA-CARRARA', 'MT' => 'MATERA', 'ME' => 'MESSINA', 'MI' => 'MILANO',
    'MO' => 'MODENA', 'MB' => 'MONZA-E-DELLA-BRIANZA', 'NA' => 'NAPOLI', 'NO' => 'NOVARA',
    'NU' => 'NUORO', 'OR' => 'ORISTANO', 'PD' => 'PADOVA', 'PA' => 'PALERMO',
    'PR' => 'PARMA', 'PV' => 'PAVIA', 'PG' => 'PERUGIA', 'PU' => 'PESARO-E-URBINO',
    'PE' => 'PESCARA', 'PC' => 'PIACENZA', 'PI' => 'PISA', 'PT' => 'PISTOIA',
    'PN' => 'PORDENONE', 'PZ' => 'POTENZA', 'PO' => 'PRATO', 'RG' => 'RAGUSA',
    'RA' => 'RAVENNA', 'RC' => 'REGGIO-DI-CALABRIA', 'RE' => 'REGGIO-NELLEMILIA', 'RI' => 'RIETI',
    'RN' => 'RIMINI', 'RM' => 'ROMA', 'RO' => 'ROVIGO', 'SA' => 'SALERNO',
    'SS' => 'SASSARI', 'SV' => 'SAVONA', 'SI' => 'SIENA', 'SR' => 'SIRACUSA',
    'SO' => 'SONDRIO', 'SU' => 'SUD-SARDEGNA', 'TA' => 'TARANTO', 'TE' => 'TERAMO',
    'TR' => 'TERNI', 'TO' => 'TORINO', 'TP' => 'TRAPANI', 'TN' => 'TRENTO',
    'TV' => 'TREVISO', 'TS' => 'TRIESTE', 'UD' => 'UDINE', 'VA' => 'VARESE',
    'VE' => 'VENEZIA', 'VB' => 'VERBANO-CUSIO-OSSOLA', 'VC' => 'VERCELLI', 'VR' => 'VERONA',
    'VV' => 'VIBO-VALENTIA', 'VI' => 'VICENZA', 'VT' => 'VITERBO',
];

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$codComune = strtoupper(trim((string)($_GET['cod_comune'] ?? '')));
$comune = trim((string)($_GET['comune'] ?? ''));
$provincia = strtoupper(trim((string)($_GET['provincia'] ?? '')));
$foglio = normalizeLookupValue((string)($_GET['foglio'] ?? ''));
$particella = normalizeLookupValue((string)($_GET['particella'] ?? ''));

if ($foglio === '' || $particella === '' || ($codComune === '' && ($comune === '' || $provincia === ''))) {
    sendResponse(['ok' => false, 'error' => 'Parametri insufficienti'], 400);
}

if (!file_exists(DB_PATH)) {
    sendResponse(['ok' => false, 'error' => 'Database catasto non disponibile'], 404);
}

$db = new SQLite3(DB_PATH, SQLITE3_OPEN_READONLY);
$db->busyTimeout(5000);

try {
    if ($codComune !== '') {
        $stmt = $db->prepare('
            SELECT cod_comune, comune, provincia, foglio, particella, lat, lng, area_mq
            FROM particelle
            WHERE cod_comune = :cod_comune AND foglio = :foglio AND particella = :particella
            LIMIT 1
        ');
        $stmt->bindValue(':cod_comune', $codComune, SQLITE3_TEXT);
    } else {
        $stmt = $db->prepare('
            SELECT cod_comune, comune, provincia, foglio, particella, lat, lng, area_mq
            FROM particelle
            WHERE comune = :comune COLLATE NOCASE
              AND provincia IN (:provincia, :provincia_alt)
              AND foglio = :foglio
              AND particella = :particella
            LIMIT 1
        ');
        $stmt->bindValue(':comune', normalizeComune($comune), SQLITE3_TEXT);
        $stmt->bindValue(':provincia', normalizeProvincia($provincia), SQLITE3_TEXT);
        $stmt->bindValue(':provincia_alt', expandProvincia($provincia), SQLITE3_TEXT);
    }

    $stmt->bindValue(':foglio', $foglio, SQLITE3_TEXT);
    $stmt->bindValue(':particella', $particella, SQLITE3_TEXT);

    $result = $stmt->execute();
    $row = $result ? $result->fetchArray(SQLITE3_ASSOC) : false;

    if (!$row) {
        sendResponse(['ok' => false, 'error' => 'Particella non trovata'], 404);
    }

    sendResponse([
        'ok' => true,
        'cod_comune' => $row['cod_comune'],
        'comune' => $row['comune'],
        'provincia' => $row['provincia'],
        'foglio' => $row['foglio'],
        'particella' => $row['particella'],
        'lat' => (float)$row['lat'],
        'lng' => (float)$row['lng'],
        'area_mq' => $row['area_mq'] !== null ? (float)$row['area_mq'] : null,
        'source' => 'catasto_db',
    ]);
} finally {
    $db->close();
}

function normalizeLookupValue(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }

    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if ($digits === '') {
        return $value;
    }

    $digits = ltrim($digits, '0');
    return $digits === '' ? '0' : $digits;
}

function normalizeComune(string $comune): string
{
    $comune = strtoupper(trim($comune));
    $comune = preg_replace('/[_\-]+/', ' ', $comune) ?? '';
    return preg_replace('/\s+/', ' ', $comune) ?? '';
}

function normalizeProvincia(string $provincia): string
{
    $provincia = strtoupper(trim($provincia));
    $provincia = preg_replace('/\s+/', '-', $provincia) ?? '';
    return preg_replace('/[^A-Z\-]/', '', $provincia) ?? '';
}

function expandProvincia(string $provincia): string
{
    $provincia = normalizeProvincia($provincia);
    if ($provincia === '') {
        return '';
    }

    return PROVINCE_MAP[$provincia] ?? $provincia;
}

function sendResponse(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
