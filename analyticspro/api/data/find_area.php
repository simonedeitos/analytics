<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/api_bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/gml_catalog.php';

analyticspro_api_guard();
analyticspro_api_require_auth();

try {
    $comune = trim((string) ($_POST['comune'] ?? analyticspro_get('comune', '')));
    $belfiore = strtoupper(trim((string) ($_POST['belfiore'] ?? analyticspro_get('belfiore', ''))));
    $foglio = trim((string) ($_POST['foglio'] ?? analyticspro_get('foglio', '')));
    $particella = trim((string) ($_POST['particella'] ?? analyticspro_get('particella', '')));

    if ($comune === '' && $belfiore === '') {
        throw new RuntimeException('Comune mancante.');
    }
    if ($foglio === '') {
        throw new RuntimeException('Foglio mancante.');
    }

    if ($belfiore === '') {
        $belfiore = analyticspro_gml_belfiore_da_comune($comune) ?? '';
    }
    if ($belfiore === '') {
        throw new RuntimeException('Comune non trovato nel catalogo GML.');
    }

    if ($particella !== '') {
        $resolved = analyticspro_gml_lookup($belfiore, $foglio, $particella);
        if ($resolved === null) {
            $diagnose = analyticspro_gml_diagnose_lookup($belfiore, $foglio, $particella);
            throw new RuntimeException((string) ($diagnose['note'] ?? 'Particella non trovata.'));
        }
        analyticspro_json([
            'ok' => true,
            'lat' => (float) $resolved['lat'],
            'lng' => (float) $resolved['lon'],
            'comune' => analyticspro_gml_nome_comune($belfiore),
            'belfiore' => $belfiore,
            'foglio' => $resolved['cod_foglio'] ?? analyticspro_gml_codice_foglio($foglio),
            'particella' => $particella,
            'message' => 'Particella trovata.',
            'zoom' => 18,
        ]);
    }

    $bounds = analyticspro_gml_lookup_foglio_bounds($belfiore, $foglio);
    if ($bounds === null) {
        $diagnose = analyticspro_gml_diagnose_lookup($belfiore, $foglio, '0');
        throw new RuntimeException((string) ($diagnose['note'] ?? 'Foglio non trovato.'));
    }

    analyticspro_json([
        'ok' => true,
        'lat' => (float) $bounds['lat'],
        'lng' => (float) $bounds['lng'],
        'comune' => analyticspro_gml_nome_comune($belfiore),
        'belfiore' => $belfiore,
        'foglio' => $bounds['cod_foglio'],
        'bounds' => [
            'min_lat' => (float) $bounds['min_lat'],
            'min_lng' => (float) $bounds['min_lng'],
            'max_lat' => (float) $bounds['max_lat'],
            'max_lng' => (float) $bounds['max_lng'],
        ],
        'message' => 'Foglio trovato.',
        'zoom' => 16,
    ]);
} catch (Throwable $exception) {
    analyticspro_json(['ok' => false, 'error' => $exception->getMessage()], 422);
}
