<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

date_default_timezone_set('Europe/Rome');

require_once __DIR__ . '/../analyticspro/includes/wfs_lookup.php';

$comune     = analyticspro_wfs_normalize_comune((string) ($_GET['comune']     ?? ''));
$provincia  = analyticspro_wfs_normalize_provincia((string) ($_GET['provincia'] ?? ''));
$foglio     = analyticspro_wfs_normalize_token((string) ($_GET['foglio']     ?? ''));
$particella = analyticspro_wfs_normalize_token((string) ($_GET['particella'] ?? ''));

if ($comune === '' || $provincia === '' || $foglio === '' || $particella === '') {
    sendJson(['ok' => false, 'error' => 'Parametri mancanti'], 400);
}

$codCatastale = analyticspro_wfs_lookup_cod_catastale($comune, $provincia);
if ($codCatastale === null) {
    sendJson(['ok' => false, 'error' => "Comune non trovato: {$comune} ({$provincia})"], 404);
}

$result = analyticspro_wfs_lookup_particella($codCatastale, $foglio, $particella);
$status = 200;
if (!($result['ok'] ?? false)) {
    $status = ($result['status'] ?? '') === 'not_found' ? 404 : 500;
}
sendJson($result, $status);

function sendJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}
