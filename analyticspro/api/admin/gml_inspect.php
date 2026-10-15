<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/api_bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/gml_catalog.php';

analyticspro_api_guard();
analyticspro_api_require_auth();
if (!analyticspro_is_admin()) {
    analyticspro_json(['ok' => false, 'error' => 'Accesso negato.'], 403);
}
session_write_close();
register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('[gml_inspect] ' . $error['message']);
    }
});

$belfiore = strtoupper(trim((string) ($_GET['belfiore'] ?? $_POST['belfiore'] ?? '')));
if (!preg_match('/^[A-Z][0-9]{3}$/', $belfiore)) {
    analyticspro_json(['ok' => false, 'error' => 'Codice Belfiore non valido.'], 400);
}

try {
$deadline = microtime(true) + 20;
$checkDeadline = static function () use ($deadline): void {
    if (microtime(true) >= $deadline) {
        throw new RuntimeException('Timeout diagnostica: comune troppo grande per una singola richiesta HTTP.');
    }
};
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

    analyticspro_gml_stream_parcels($plePath, static function (array $f) use (&$count, &$fogli, &$esempi, $checkDeadline): bool {
        $checkDeadline();
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

    analyticspro_gml_stream_zonings($mapPath, static function (array $f) use (&$count, &$fogli, &$esempi, $checkDeadline): bool {
        $checkDeadline();
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
} catch (Throwable $e) {
    error_log('[gml_inspect] ' . $belfiore . ': ' . $e->getMessage());
    analyticspro_json(['ok' => false, 'error' => 'Diagnostica non riuscita: errore di lettura GML o limite di tempo superato. Verificare i file e il log PHP del server.'], 500);
}
