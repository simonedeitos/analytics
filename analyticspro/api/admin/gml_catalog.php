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
    // Lista catalogo
    $catalog = analyticspro_gml_build_catalog();

    // Arricchisce con conteggi dall'indice SQLite
    $result = [];
    foreach ($catalog as $belfiore => $entry) {
        $item = [
            'belfiore'  => $belfiore,
            'nome'      => $entry['nome'] ?? '',
            'has_ple'   => !empty($entry['ple']),
            'has_map'   => !empty($entry['map']),
            'size_ple'  => (int) ($entry['size_ple'] ?? 0),
            'size_map'  => (int) ($entry['size_map'] ?? 0),
            'mtime'     => (int) ($entry['mtime'] ?? 0),
            'indexed'   => analyticspro_gml_parcel_index_valid($belfiore),
            'n_parcels' => 0,
            'n_fogli'   => 0,
        ];

        // Conta particelle nell'indice SQLite se disponibile
        $dbPath = analyticspro_gml_index_dir() . '/' . $belfiore . '.sqlite';
        if (is_file($dbPath)) {
            try {
                $db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);
                $row = $db->querySingle('SELECT value FROM meta WHERE key = "count"');
                if ($row !== false && $row !== null) {
                    $item['n_parcels'] = (int) $row;
                }
            } catch (Throwable $e) {
                // ignora
            }
        }

        // Conta fogli
        $fogliPath = analyticspro_gml_index_dir() . '/' . $belfiore . '_fogli.json';
        if (is_file($fogliPath)) {
            $fogli = json_decode((string) file_get_contents($fogliPath), true);
            if (is_array($fogli)) {
                $item['n_fogli'] = count($fogli);
            }
        }

        $result[] = $item;
    }

    analyticspro_json(['ok' => true, 'catalog' => $result]);
}

if ($method === 'POST') {
    analyticspro_verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? null);

    $action = strtolower(trim((string) ($_POST['action'] ?? '')));

    if ($action === 'rebuild') {
        analyticspro_gml_build_catalog(true);
        analyticspro_json(['ok' => true, 'message' => 'Catalogo rigenerato.']);
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
