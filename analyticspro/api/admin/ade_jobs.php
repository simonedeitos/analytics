<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/ade_import.php';
require_once ANALYTICSPRO_ROOT . '/includes/api_bootstrap.php';

analyticspro_api_guard();
analyticspro_api_require_auth();
if (!analyticspro_is_admin()) {
    analyticspro_json(['ok' => false, 'error' => 'Accesso negato.'], 403);
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        analyticspro_verify_csrf($_POST['csrf_token'] ?? null);
        $importType = strtolower(trim((string) ($_POST['import_type'] ?? 'zip')));
        $allowedExtensions = $importType === 'sql' ? ['sql'] : ['zip'];

        if (empty($_FILES['files'])) {
            throw new RuntimeException($importType === 'sql' ? 'Nessun file SQL inviato.' : 'Nessun file ZIP inviato.');
        }

        $createdJobIds = [];
        $names = $_FILES['files']['name'];
        $tmpNames = $_FILES['files']['tmp_name'];
        $errors = $_FILES['files']['error'];

        foreach ($names as $index => $name) {
            if (($errors[$index] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                continue;
            }

            $filename = analyticspro_ade_validate_filename((string) $name, $allowedExtensions);
            if ($filename === null) {
                throw new RuntimeException($importType === 'sql' ? 'Il file caricato deve avere estensione .sql.' : 'Il file caricato deve avere estensione .zip.');
            }

            $pdo = analyticspro_db();
            $jobId = analyticspro_ade_create_job($pdo, $filename, (int) analyticspro_current_user()['id']);
            $destination = analyticspro_ade_prepare_destination_path($jobId, $filename);
            if (!move_uploaded_file((string) $tmpNames[$index], $destination)) {
                throw new RuntimeException('Impossibile salvare il file caricato.');
            }

            if (!analyticspro_ade_launch_job($jobId, $destination, $importType)) {
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
