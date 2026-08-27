<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/api_bootstrap.php';

analyticspro_api_guard();
analyticspro_api_require_auth();
if (!analyticspro_is_admin()) {
    analyticspro_json(['ok' => false, 'error' => 'Accesso negato.'], 403);
}

$manualUploadDir = ANALYTICSPRO_ROOT . '/storage/manual_upload';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $files = [];
        if (is_dir($manualUploadDir)) {
            foreach (new DirectoryIterator($manualUploadDir) as $fileInfo) {
                if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'zip') {
                    continue;
                }
                $files[] = [
                    'name' => $fileInfo->getFilename(),
                    'size' => $fileInfo->getSize(),
                    'mtime' => $fileInfo->getMTime(),
                ];
            }
        }
        usort($files, static fn(array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));
        analyticspro_json(['ok' => true, 'files' => $files]);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        analyticspro_verify_csrf($_POST['csrf_token'] ?? null);

        $selectedNames = $_POST['files'] ?? [];
        if (!is_array($selectedNames) || $selectedNames === []) {
            throw new RuntimeException('Nessun file selezionato.');
        }

        $pdo = analyticspro_db();
        $createdJobIds = [];
        $errors = [];

        foreach ($selectedNames as $rawName) {
            $name = basename((string) $rawName);
            if ($name === '' || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') {
                $errors[] = ['name' => (string) $rawName, 'error' => 'Nome file non valido.'];
                continue;
            }

            $sourcePath = $manualUploadDir . '/' . $name;
            $realSource = realpath($sourcePath);
            $realDir = realpath($manualUploadDir);
            if ($realSource === false || $realDir === false || !str_starts_with($realSource, $realDir . DIRECTORY_SEPARATOR)) {
                $errors[] = ['name' => $name, 'error' => 'File non trovato o percorso non valido.'];
                continue;
            }

            $province = strtoupper(pathinfo($name, PATHINFO_FILENAME));
            $pdo->prepare(
                "INSERT INTO ade_import_jobs (provincia_sigla, zip_filename, status, created_by)
                 VALUES (:provincia_sigla, :zip_filename, 'queued', :created_by)"
            )->execute([
                'provincia_sigla' => $province,
                'zip_filename' => $name,
                'created_by' => analyticspro_current_user()['id'],
            ]);
            $jobId = (int) $pdo->lastInsertId();

            $destination = ANALYTICSPRO_ROOT . '/storage/ade_uploads/job_' . $jobId . '_' . $name;
            if (!rename($realSource, $destination)) {
                $errors[] = ['name' => $name, 'error' => 'Impossibile spostare il file.'];
                $pdo->prepare('DELETE FROM ade_import_jobs WHERE id = :id')->execute(['id' => $jobId]);
                continue;
            }

            $worker = ANALYTICSPRO_ROOT . '/cron/ade_import_worker.php';
            if (!analyticspro_launch_background($worker, [$jobId, $destination])) {
                require ANALYTICSPRO_ROOT . '/cron/ade_import_worker.php';
                analyticspro_run_ade_import_job($jobId, $destination);
            }

            $createdJobIds[] = $jobId;
        }

        analyticspro_json(['ok' => true, 'job_ids' => $createdJobIds, 'errors' => $errors]);
    }

    analyticspro_json(['ok' => false, 'error' => 'Metodo non supportato.'], 405);
} catch (Throwable $exception) {
    analyticspro_json(['ok' => false, 'error' => $exception->getMessage()], 422);
}
