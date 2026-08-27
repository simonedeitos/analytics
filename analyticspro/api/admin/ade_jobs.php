<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/api_bootstrap.php';

analyticspro_api_guard();
analyticspro_api_require_auth();
if (!analyticspro_is_admin()) {
    analyticspro_json(['ok' => false, 'error' => 'Accesso negato.'], 403);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        analyticspro_verify_csrf($_POST['csrf_token'] ?? null);
        if (empty($_FILES['files'])) {
            throw new RuntimeException('Nessun file ZIP inviato.');
        }

        $createdJobIds = [];
        $names = $_FILES['files']['name'];
        $tmpNames = $_FILES['files']['tmp_name'];
        $errors = $_FILES['files']['error'];

        foreach ($names as $index => $name) {
            if (($errors[$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }

            $province = strtoupper(pathinfo((string) $name, PATHINFO_FILENAME));
            $pdo = analyticspro_db();
            $pdo->prepare("INSERT INTO ade_import_jobs (provincia_sigla, zip_filename, status, created_by) VALUES (:provincia_sigla, :zip_filename, 'queued', :created_by)")
                ->execute([
                    'provincia_sigla' => $province,
                    'zip_filename' => basename((string) $name),
                    'created_by' => analyticspro_current_user()['id'],
                ]);
            $jobId = (int) $pdo->lastInsertId();
            $destination = ANALYTICSPRO_ROOT . '/storage/ade_uploads/job_' . $jobId . '_' . basename((string) $name);
            if (!move_uploaded_file((string) $tmpNames[$index], $destination)) {
                throw new RuntimeException('Impossibile salvare il file caricato.');
            }

            $worker = ANALYTICSPRO_ROOT . '/cron/ade_import_worker.php';
            if (!analyticspro_launch_background($worker, [$jobId, $destination])) {
                // Impossibile avviare un processo separato: NON eseguire il worker in modo
               // sincrono dentro questa richiesta HTTP, altrimenti per ZIP grandi
              // (province come Roma/Milano) si va in timeout 504 sul reverse proxy anche
              // se il worker prosegue lato server. Segnala l'errore in modo esplicito.
              analyticspro_ade_log($jobId, 'error', 'Impossibile avviare il worker in background (proc_open/shell_exec non disponibili su questo hosting). Contattare l\'amministratore di sistema per abilitare l\'esecuzione di processi in background, oppure configurare un cron job che esegua manualmente: php ' . $worker . ' ' . $jobId . ' ' . $destination);
             $pdo->prepare("UPDATE ade_import_jobs SET status = 'failed', error_message = :error_message WHERE id = :id")
             ->execute([
                   'error_message' => 'Impossibile avviare il worker in background su questo hosting.',
                  'id' => $jobId,
               ]);
              continue;
            }   
            $createdJobIds[] = $jobId;
        }

        analyticspro_json(['ok' => true, 'job_ids' => $createdJobIds]);
    }

    $jobId = (int) analyticspro_get('job_id', 0);
    if ($jobId > 0) {
        $stmt = analyticspro_db()->prepare('SELECT * FROM ade_import_jobs WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $jobId]);
        $job = $stmt->fetch();
        if (!$job) {
            throw new RuntimeException('Job non trovato.');
        }
        $afterId = (int) analyticspro_get('after_id', 0);
        $logsStmt = analyticspro_db()->prepare(
            'SELECT id, level, message, created_at FROM ade_import_job_log WHERE job_id = :job_id AND id > :after_id ORDER BY id ASC'
        );
        $logsStmt->execute(['job_id' => $jobId, 'after_id' => $afterId]);
        analyticspro_json(['ok' => true, 'job' => $job, 'logs' => $logsStmt->fetchAll()]);
    }

    $jobs = analyticspro_db()->query('SELECT * FROM ade_import_jobs ORDER BY id DESC LIMIT 10')->fetchAll();
    analyticspro_json(['ok' => true, 'jobs' => $jobs]);
} catch (Throwable $exception) {
    analyticspro_json(['ok' => false, 'error' => $exception->getMessage()], 422);
}
