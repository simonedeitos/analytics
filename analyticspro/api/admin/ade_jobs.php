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
                require ANALYTICSPRO_ROOT . '/cron/ade_import_worker.php';
                analyticspro_run_ade_import_job($jobId, $destination);
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
        $logs = analyticspro_db()->prepare('SELECT level, message, created_at FROM ade_import_job_log WHERE job_id = :job_id ORDER BY id DESC LIMIT 50');
        $logs->execute(['job_id' => $jobId]);
        analyticspro_json(['ok' => true, 'job' => $job, 'logs' => array_reverse($logs->fetchAll())]);
    }

    $jobs = analyticspro_db()->query('SELECT * FROM ade_import_jobs ORDER BY id DESC LIMIT 10')->fetchAll();
    analyticspro_json(['ok' => true, 'jobs' => $jobs]);
} catch (Throwable $exception) {
    analyticspro_json(['ok' => false, 'error' => $exception->getMessage()], 422);
}
