<?php

declare(strict_types=1);

/**
 * Avvia la costruzione dell'indice particelle SQLite per uno o più comuni.
 * L'elaborazione avviene in-process (sincrona) dato che questo endpoint
 * viene tipicamente chiamato con fetch() e letto via SSE / polling.
 *
 * POST params:
 *   belfiore  = codice Belfiore singolo o lista separata da virgola
 *   csrf_token
 */

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/api_bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/gml_catalog.php';

analyticspro_api_guard();
analyticspro_api_require_auth();
if (!analyticspro_is_admin()) {
    analyticspro_json(['ok' => false, 'error' => 'Accesso negato.'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    analyticspro_json(['ok' => false, 'error' => 'Metodo non supportato.'], 405);
}

analyticspro_verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? null);

$rawBelfiore = trim((string) ($_POST['belfiore'] ?? 'ALL'));
$catalog     = analyticspro_gml_build_catalog();

if (strtoupper($rawBelfiore) === 'ALL') {
    $codes = array_keys($catalog);
} else {
    $codes = array_map('strtoupper', array_filter(array_map('trim', explode(',', $rawBelfiore))));
}

$results = [];
foreach ($codes as $belfiore) {
    if (!preg_match('/^[A-Z][0-9]{3}$/', $belfiore)) {
        $results[$belfiore] = ['ok' => false, 'error' => 'Codice non valido'];
        continue;
    }

    $entry = $catalog[$belfiore] ?? null;
    if ($entry === null || empty($entry['ple'])) {
        $results[$belfiore] = ['ok' => false, 'error' => 'File _ple.gml non trovato'];
        continue;
    }

    try {
        $n = analyticspro_gml_build_parcel_index($belfiore);
        $results[$belfiore] = ['ok' => true, 'n_parcels' => $n];
    } catch (Throwable $e) {
        $results[$belfiore] = ['ok' => false, 'error' => $e->getMessage()];
        error_log('[gml_index_build] Errore per ' . $belfiore . ': ' . $e->getMessage());
    }
}

analyticspro_json(['ok' => true, 'results' => $results]);
