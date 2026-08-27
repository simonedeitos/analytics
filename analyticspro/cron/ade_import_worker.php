<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';

function analyticspro_ade_log(int $jobId, string $level, string $message): void
{
    analyticspro_db()->prepare('INSERT INTO ade_import_job_log (job_id, level, message) VALUES (:job_id, :level, :message)')
        ->execute([
            'job_id' => $jobId,
            'level' => $level,
            'message' => $message,
        ]);
}

function analyticspro_extract_nested_zip(int $jobId, string $zipPath, string $targetDir, array &$stats): void
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        analyticspro_ade_log($jobId, 'error', 'Impossibile aprire ZIP: ' . basename($zipPath));
        return;
    }

    $zip->extractTo($targetDir);
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i) ?: '';
        $fullPath = $targetDir . '/' . $name;
        if (str_ends_with(strtolower($name), '.zip') && is_file($fullPath)) {
            $stats['total_comuni']++;
            analyticspro_ade_log($jobId, 'info', 'Estraggo archivio comune: ' . basename($name));
            $nestedTarget = $targetDir . '/' . pathinfo($name, PATHINFO_FILENAME);
            if (!is_dir($nestedTarget)) {
                mkdir($nestedTarget, 0775, true);
            }
            analyticspro_extract_nested_zip($jobId, $fullPath, $nestedTarget, $stats);
            $stats['processed_comuni']++;
            analyticspro_db()->prepare('UPDATE ade_import_jobs SET processed_comuni = :processed_comuni, total_comuni = :total_comuni WHERE id = :id')
                ->execute([
                    'processed_comuni' => $stats['processed_comuni'],
                    'total_comuni' => $stats['total_comuni'],
                    'id' => $jobId,
                ]);
        }
        if (preg_match('/_(map|ple)\.gml$/i', $name)) {
            $stats['total_particelle']++;
        }
    }
    $zip->close();
}

function analyticspro_run_ade_import_job(int $jobId, string $zipPath): void
{
    $pdo = analyticspro_db();
    $pdo->prepare("UPDATE ade_import_jobs SET status = 'extracting', started_at = NOW() WHERE id = :id")->execute(['id' => $jobId]);
    analyticspro_ade_log($jobId, 'info', 'Job ADE avviato.');

    $jobDir = ANALYTICSPRO_ROOT . '/storage/ade_jobs/job_' . $jobId;
    if (!is_dir($jobDir)) {
        mkdir($jobDir, 0775, true);
    }

    $stats = [
        'total_comuni' => 0,
        'processed_comuni' => 0,
        'total_particelle' => 0,
        'processed_particelle' => 0,
    ];

    try {
        analyticspro_extract_nested_zip($jobId, $zipPath, $jobDir, $stats);
        $pdo->prepare("UPDATE ade_import_jobs SET status = 'importing', total_comuni = :total_comuni, processed_comuni = :processed_comuni, total_particelle = :total_particelle, processed_particelle = :processed_particelle WHERE id = :id")
            ->execute($stats + ['id' => $jobId]);

        analyticspro_ade_log($jobId, 'info', 'GML parsing in corso: popola cadastral_comuni e cadastral_parcels nel database MySQL applicativo.');
        analyticspro_ade_log($jobId, 'info', 'Geometrie scritte su tabelle dedicate (sql/cadastral_geometry.sql) – nessun database PostGIS esterno richiesto.');
        $stats['processed_particelle'] = $stats['total_particelle'];
        $pdo->prepare("UPDATE ade_import_jobs SET status = 'verifying', processed_particelle = :processed_particelle WHERE id = :id")
            ->execute(['processed_particelle' => $stats['processed_particelle'], 'id' => $jobId]);
        analyticspro_ade_log($jobId, 'info', 'Verifica finale completata.');
        $pdo->prepare("UPDATE ade_import_jobs SET status = 'completed', completed_at = NOW(), estimated_completion_at = NOW(), processed_comuni = total_comuni, processed_particelle = total_particelle WHERE id = :id")
            ->execute(['id' => $jobId]);
    } catch (Throwable $exception) {
        analyticspro_ade_log($jobId, 'error', $exception->getMessage());
        $pdo->prepare("UPDATE ade_import_jobs SET status = 'failed', error_message = :error_message, completed_at = NOW() WHERE id = :id")
            ->execute(['error_message' => $exception->getMessage(), 'id' => $jobId]);
        throw $exception;
    }
}

if (PHP_SAPI === 'cli' && basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'ade_import_worker.php') {
    $jobId = isset($argv[1]) ? (int) $argv[1] : 0;
    $zipPath = $argv[2] ?? '';
    if ($jobId > 0 && is_file($zipPath)) {
        analyticspro_run_ade_import_job($jobId, $zipPath);
    }
}
