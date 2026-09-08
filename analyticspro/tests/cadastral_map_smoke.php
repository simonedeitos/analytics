<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/cadastral_map.php';

$pass = true;
$errors = [];

$targetUrl = analyticspro_cadastral_wms_build_target_url(
    'https://wms.cartografia.agenziaentrate.gov.it/inspire/wms/ows01.php?language=ita',
    [
        'SERVICE' => 'WMS',
        'REQUEST' => 'GetMap',
        'LAYERS' => 'CP.CadastralParcel',
        'BBOX' => '10,45,11,46',
        'WIDTH' => '256',
        'HEIGHT' => '256',
    ]
);
if (strpos($targetUrl, 'wms.cartografia.agenziaentrate.gov.it/inspire/wms/ows01.php?') === false) {
    $pass = false;
    $errors[] = 'Il proxy WMS non costruisce il path atteso.';
}
if (strpos($targetUrl, 'language=ita') === false || strpos($targetUrl, 'REQUEST=GetMap') === false) {
    $pass = false;
    $errors[] = 'Il proxy WMS non preserva/integra i parametri attesi.';
}

$rawTargetUrl = analyticspro_cadastral_wms_build_target_url_with_raw_query(
    'https://wms.cartografia.agenziaentrate.gov.it/inspire/wms/ows01.php?language=ita',
    ['url' => 'ignored'],
    'url=' . rawurlencode('https://wms.cartografia.agenziaentrate.gov.it/inspire/wms/ows01.php?language=ita')
    . '&SERVICE=WMS&REQUEST=GetMap&LAYERS=CP.CadastralParcel&VERSION=1.3.0&CRS=EPSG%3A4258&BBOX=44%2C10%2C45%2C11&WIDTH=256&HEIGHT=256&STYLES='
);
if (strpos($rawTargetUrl, 'CRS=EPSG%3A4258') === false || strpos($rawTargetUrl, 'STYLES=') === false) {
    $pass = false;
    $errors[] = 'Il proxy WMS non preserva la query string raw del layer catastale.';
}

try {
    analyticspro_cadastral_wms_build_target_url('https://example.com/wms', []);
    $pass = false;
    $errors[] = 'Il proxy WMS deve rifiutare host non in whitelist.';
} catch (RuntimeException) {
}

$fromReverse = analyticspro_cadastral_complete_fields([
    'comune' => '',
    'provincia' => '',
    'cod_catastale' => '',
    'foglio' => '12',
    'particella' => '34',
], 45.412551, 10.391021, static function (): array {
    return ['comune' => 'Montichiari', 'provincia' => 'Brescia'];
});
if (($fromReverse['comune'] ?? '') !== 'MONTICHIARI' || ($fromReverse['provincia'] ?? '') !== 'BS' || ($fromReverse['cod_catastale'] ?? '') !== 'F471') {
    $pass = false;
    $errors[] = 'Il fallback reverse-geocoding non normalizza comune/provincia/codice catastale.';
}

$reverseOnly = analyticspro_cadastral_complete_fields([], 45.412551, 10.391021, static function (): array {
    return ['comune' => 'Montichiari', 'provincia' => 'Brescia'];
});
if (($reverseOnly['comune'] ?? '') !== 'MONTICHIARI' || ($reverseOnly['provincia'] ?? '') !== 'BS' || ($reverseOnly['cod_catastale'] ?? '') !== 'F471') {
    $pass = false;
    $errors[] = 'Il completamento solo da coordinata non ricostruisce comune/provincia/codice catastale.';
}

$fromCode = analyticspro_cadastral_complete_fields([
    'comune' => '',
    'provincia' => '',
    'cod_catastale' => 'B394',
    'foglio' => '33',
    'particella' => '147',
]);
if (($fromCode['comune'] ?? '') !== 'CALCINATO' || ($fromCode['provincia'] ?? '') !== 'BS') {
    $pass = false;
    $errors[] = 'Il completamento da codice catastale non ricostruisce comune/provincia.';
}

if ($pass) {
    echo "PASS: cadastral map helpers OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: {$error}\n";
}
exit(1);
