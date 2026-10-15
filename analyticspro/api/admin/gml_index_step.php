<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/api_bootstrap.php';
analyticspro_api_guard();
require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/gml_catalog.php';
require_once ANALYTICSPRO_ROOT . '/includes/gml_index_worker_logic.php';

analyticspro_api_require_auth();
if (!analyticspro_is_admin()) {
    analyticspro_json(['ok' => false, 'error' => 'Accesso negato.'], 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    analyticspro_json(['ok' => false, 'error' => 'Metodo non supportato.'], 405);
}
try {
    analyticspro_verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? null);
} catch (RuntimeException $e) {
    analyticspro_json(['ok' => false, 'error' => 'Token CSRF non valido.'], 403);
}
$jobId = (int) ($_POST['job_id'] ?? 0);
if ($jobId <= 0) {
    analyticspro_json(['ok' => false, 'error' => 'Parametro job_id mancante.'], 400);
}

// Il polling dei log deve poter usare la stessa sessione durante il lavoro.
session_write_close();
try {
    $pdo = analyticspro_db();
    $stmt = $pdo->prepare('SELECT id FROM gml_index_jobs WHERE id = :id');
    $stmt->execute(['id' => $jobId]);
    if (!$stmt->fetchColumn()) {
        analyticspro_json(['ok' => false, 'error' => 'Job non trovato.'], 404);
    }
    analyticspro_json(['ok' => true] + analyticspro_gml_index_step($pdo, $jobId));
} catch (Throwable $e) {
    error_log('[gml_index_step] ' . $e->getMessage());
    analyticspro_json(['ok' => false, 'error' => 'Impossibile eseguire lo step. Verificare la migration 019 e il log PHP del server.'], 500);
}
