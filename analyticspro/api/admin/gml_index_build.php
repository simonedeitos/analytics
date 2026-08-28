<?php

declare(strict_types=1);

/**
 * Accoda un job di indicizzazione GML in background e lo avvia tramite worker.
 * Stesso pattern di ade_jobs.php / ade_import_worker.php.
 *
 * POST params:
 *   belfiore    = codice Belfiore singolo, lista separata da virgola, o "ALL"
 *   csrf_token
 *
 * GET params:
 *   job_id      = leggi stato e log di un job specifico
 *   after_id    = ID dell'ultimo log ricevuto (per polling incrementale)
 */

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/api_bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/gml_catalog.php';

analyticspro_api_guard();
analyticspro_api_require_auth();
if (!analyticspro_is_admin()) {
    analyticspro_json(['ok' => false, 'error' => 'Accesso negato.'], 403);
}

// ── GET: polling stato e log ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $jobId = (int) ($_GET['job_id'] ?? 0);
    if ($jobId <= 0) {
        analyticspro_json(['ok' => false, 'error' => 'Parametro job_id mancante.'], 400);
    }

    $pdo = analyticspro_db();
    $stmt = $pdo->prepare('SELECT * FROM gml_index_jobs WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$job) {
        analyticspro_json(['ok' => false, 'error' => 'Job non trovato.'], 404);
    }

    $afterId = (int) ($_GET['after_id'] ?? 0);
    $logsStmt = $pdo->prepare(
        'SELECT id, level, message, created_at FROM gml_index_job_log WHERE job_id = :job_id AND id > :after_id ORDER BY id ASC'
    );
    $logsStmt->execute(['job_id' => $jobId, 'after_id' => $afterId]);
    analyticspro_json(['ok' => true, 'job' => $job, 'logs' => $logsStmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ── POST: accoda il job ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    analyticspro_json(['ok' => false, 'error' => 'Metodo non supportato.'], 405);
}

analyticspro_verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? null);

$rawBelfiore = strtoupper(trim((string) ($_POST['belfiore'] ?? 'ALL')));
if ($rawBelfiore !== 'ALL') {
    // Valida ogni codice nella lista
    $codes = array_filter(array_map('trim', explode(',', $rawBelfiore)));
    foreach ($codes as $c) {
        if (!preg_match('/^[A-Z][0-9]{3}$/', $c)) {
            analyticspro_json(['ok' => false, 'error' => 'Codice Belfiore non valido: ' . $c], 400);
        }
    }
}

$pdo       = analyticspro_db();
$userId    = (int) (analyticspro_current_user()['id'] ?? 0);

$pdo->prepare("INSERT INTO gml_index_jobs (belfiore, status, created_by) VALUES (:belfiore, 'queued', :user)")
    ->execute(['belfiore' => $rawBelfiore, 'user' => $userId]);
$jobId = (int) $pdo->lastInsertId();

$worker = ANALYTICSPRO_ROOT . '/cron/gml_index_worker.php';

if (analyticspro_launch_background($worker, [$jobId])) {
    analyticspro_json(['ok' => true, 'job_id' => $jobId]);
}

// Impossibile avviare in background: marca il job come failed con istruzioni manuali
$errMsg = 'Impossibile avviare il worker in background (proc_open/shell_exec non disponibili su questo hosting). '
    . 'Eseguire manualmente: php ' . $worker . ' ' . $jobId;
$pdo->prepare("UPDATE gml_index_jobs SET status = 'failed', error_message = :err WHERE id = :id")
    ->execute(['err' => $errMsg, 'id' => $jobId]);

$pdo->prepare('INSERT INTO gml_index_job_log (job_id, level, message) VALUES (:job_id, :level, :message)')
    ->execute(['job_id' => $jobId, 'level' => 'error', 'message' => $errMsg]);

analyticspro_json(['ok' => false, 'job_id' => $jobId, 'error' => $errMsg], 503);

