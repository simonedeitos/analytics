<?php

declare(strict_types=1);

// L'analisi può richiedere alcuni minuti sui comuni grandi — rimuove il limite di esecuzione.
set_time_limit(0);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/api_bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/gml_catalog.php';

analyticspro_api_guard();
analyticspro_api_require_auth();
if (!analyticspro_is_admin()) {
    analyticspro_json(['ok' => false, 'error' => 'Accesso negato.'], 403);
}

$belfiore = strtoupper(trim((string) ($_GET['belfiore'] ?? $_POST['belfiore'] ?? '')));
if (!preg_match('/^[A-Z][0-9]{3}$/', $belfiore)) {
    analyticspro_json(['ok' => false, 'error' => 'Codice Belfiore non valido.'], 400);
}

$catalog = analyticspro_gml_build_catalog();
$entry   = $catalog[$belfiore] ?? null;

if ($entry === null || (empty($entry['ple']) && empty($entry['map']))) {
    analyticspro_json(['ok' => false, 'error' => 'Nessun file GML trovato per il comune ' . $belfiore . '.'], 404);
}

$report = [
    'belfiore' => $belfiore,
    'nome'     => $entry['nome'] ?? '',
    'ple'      => [],
    'map'      => [],
];

// Ispezione file _ple.gml
if (!empty($entry['ple'])) {
    $plePath        = $entry['ple'];
    $numberMatched  = analyticspro_gml_number_matched($plePath);
    $count          = 0;
    $fogli          = [];
    $esempi         = [];

    analyticspro_gml_stream_parcels($plePath, static function (array $f) use (&$count, &$fogli, &$esempi): bool {
        $count++;
        if ($f['codFoglio'] !== '') {
            $fogli[$f['codFoglio']] = true;
        }
        if (count($esempi) < 3) {
            $esempi[] = $f['ref'];
        }
        return false;
    });

    $report['ple'] = [
        'path'           => basename($plePath),
        'size'           => (int) ($entry['size_ple'] ?? 0),
        'number_matched' => $numberMatched,
        'letti'          => $count,
        'ok'             => $numberMatched === null || $count === $numberMatched,
        'fogli_distinti' => count($fogli),
        'primi_fogli'    => array_keys(array_slice($fogli, 0, 10, true)),
        'esempi_ref'     => $esempi,
    ];
}

// Ispezione file _map.gml
if (!empty($entry['map'])) {
    $mapPath       = $entry['map'];
    $numberMatched = analyticspro_gml_number_matched($mapPath);
    $count         = 0;
    $fogli         = [];
    $esempi        = [];

    analyticspro_gml_stream_zonings($mapPath, static function (array $f) use (&$count, &$fogli, &$esempi): bool {
        $count++;
        if ($f['codFoglio'] !== '') {
            $fogli[$f['codFoglio']] = true;
        }
        if (count($esempi) < 3) {
            $esempi[] = $f['ref'];
        }
        return false;
    });

    $report['map'] = [
        'path'           => basename($mapPath),
        'size'           => (int) ($entry['size_map'] ?? 0),
        'number_matched' => $numberMatched,
        'letti'          => $count,
        'ok'             => $numberMatched === null || $count === $numberMatched,
        'fogli_distinti' => count($fogli),
        'primi_fogli'    => array_keys(array_slice($fogli, 0, 10, true)),
        'esempi_ref'     => $esempi,
    ];
}

analyticspro_json(['ok' => true, 'report' => $report]);
