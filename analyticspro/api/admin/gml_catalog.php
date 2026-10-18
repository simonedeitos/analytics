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

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Lista catalogo: i conteggi arrivano solo da catalogo_counts.json
    // (generato da rebuild_counts / pagina manutenzione), nessun SQLite aperto.
    $payload = analyticspro_gml_catalog_list_payload();
    analyticspro_json([
        'ok'               => true,
        'catalog'          => $payload['catalog'],
        'counts_generated' => $payload['counts_generated'],
        'counts_available' => $payload['counts_available'],
    ]);
}

if ($method === 'POST') {
    analyticspro_verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? null);

    $action = strtolower(trim((string) ($_POST['action'] ?? '')));

    if ($action === 'rebuild') {
        analyticspro_gml_build_catalog(true);
        analyticspro_json(['ok' => true, 'message' => 'Catalogo rigenerato.']);
    }

    if ($action === 'rebuild_counts') {
        @set_time_limit(300);
        $counts = analyticspro_gml_rebuild_counts_cache();
        analyticspro_json([
            'ok'           => true,
            'message'      => 'Conteggi catalogo rigenerati.',
            'generated_at' => $counts['generated_at'],
            'count'        => $counts['count'],
        ]);
    }

    if ($action === 'delete') {
        $belfiore = strtoupper(trim((string) ($_POST['belfiore'] ?? '')));
        if (!preg_match('/^[A-Z][0-9]{3}$/', $belfiore)) {
            analyticspro_json(['ok' => false, 'error' => 'Codice Belfiore non valido.'], 400);
        }

        $catalog = analyticspro_gml_build_catalog();
        $entry   = $catalog[$belfiore] ?? null;
        if ($entry === null) {
            analyticspro_json(['ok' => false, 'error' => 'Comune non trovato nel catalogo.'], 404);
        }

        $deleted = [];
        foreach (['ple', 'map'] as $type) {
            if (!empty($entry[$type]) && is_file($entry[$type])) {
                @unlink($entry[$type]);
                $deleted[] = basename($entry[$type]);
            }
        }

        // Rimuovi indici
        foreach (['.sqlite', '_fogli.json'] as $suffix) {
            $idx = analyticspro_gml_index_dir() . '/' . $belfiore . $suffix;
            if (is_file($idx)) {
                @unlink($idx);
            }
        }

        analyticspro_gml_invalidate_catalog_cache();
        analyticspro_gml_build_catalog(true);
        analyticspro_json(['ok' => true, 'deleted' => $deleted]);
    }

    analyticspro_json(['ok' => false, 'error' => 'Azione non riconosciuta.'], 400);
}

analyticspro_json(['ok' => false, 'error' => 'Metodo non supportato.'], 405);
