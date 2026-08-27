<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/ade_import.php';

function analyticspro_run_ade_sql_import_job(int $jobId, string $sqlPath): void
{
    if (PHP_SAPI === 'cli' && function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    if (PHP_SAPI === 'cli' && function_exists('ini_set')) {
        @ini_set('memory_limit', '-1');
    }

    if (!analyticspro_ade_is_allowed_import_path($sqlPath, ['sql'])) {
        throw new RuntimeException('Percorso file SQL non consentito.');
    }

    $pdo = analyticspro_db();
    $pdo->prepare("UPDATE ade_import_jobs SET status = 'importing', started_at = NOW() WHERE id = :id")
        ->execute(['id' => $jobId]);

    analyticspro_ade_log($jobId, 'info', 'Job SQL ADE avviato.');

    $analysis = analyticspro_ade_analyze_sql_file($sqlPath);
    $stats = [
        'total_comuni' => (int) ($analysis['total_comuni'] ?? 0),
        'processed_comuni' => 0,
        'total_particelle' => (int) ($analysis['total_particelle'] ?? 0),
        'processed_particelle' => 0,
    ];

    analyticspro_update_ade_job_progress($jobId, $stats, 'importing');
    analyticspro_ade_log(
        $jobId,
        'info',
        sprintf(
            'Analisi file SQL completata: %s statement totali (%s INSERT comuni, %s INSERT particelle, %s altri statement).',
            number_format((int) ($analysis['total_statements'] ?? 0), 0, ',', '.'),
            number_format($stats['total_comuni'], 0, ',', '.'),
            number_format($stats['total_particelle'], 0, ',', '.'),
            number_format((int) ($analysis['total_other'] ?? 0), 0, ',', '.')
        )
    );

    $statementIndex = 0;
    $failedStatements = 0;
    $skippedStatements = 0;
    $progressStep = 25;
    $totalStatements = (int) ($analysis['total_statements'] ?? 0);

    try {
        analyticspro_ade_stream_sql_statements($sqlPath, static function (string $statement) use (
            &$statementIndex,
            &$failedStatements,
            &$skippedStatements,
            $jobId,
            $pdo,
            &$stats,
            $progressStep
        ): void {
            $statementIndex++;
            $type = analyticspro_ade_classify_sql_statement($statement);

            if (!in_array($type, ['comune', 'parcel'], true)) {
                $skippedStatements++;
                analyticspro_ade_log($jobId, 'warning', 'Statement SQL #' . $statementIndex . ' ignorato perché non supportato in modalità import ADE.');
                return;
            }

            try {
                $pdo->exec($statement);
            } catch (Throwable $exception) {
                $failedStatements++;
                analyticspro_ade_log($jobId, 'warning', 'Statement SQL #' . $statementIndex . ' fallito: ' . $exception->getMessage());
            }

            if ($type === 'comune') {
                $stats['processed_comuni']++;
            } elseif ($type === 'parcel') {
                $stats['processed_particelle']++;
            }

            if ($statementIndex % $progressStep === 0) {
                analyticspro_update_ade_job_progress($jobId, $stats);
                analyticspro_ade_log(
                    $jobId,
                    'info',
                    sprintf(
                        'Eseguiti %s/%s statement SQL · INSERT comuni %s/%s · INSERT particelle %s/%s.',
                        number_format($statementIndex, 0, ',', '.'),
                        number_format(max($totalStatements, $statementIndex), 0, ',', '.'),
                        number_format($stats['processed_comuni'], 0, ',', '.'),
                        number_format($stats['total_comuni'], 0, ',', '.'),
                        number_format($stats['processed_particelle'], 0, ',', '.'),
                        number_format($stats['total_particelle'], 0, ',', '.')
                    )
                );
            }
        });

        analyticspro_update_ade_job_progress($jobId, $stats, 'verifying');
        analyticspro_ade_log($jobId, 'info', 'Verifica finale del job SQL in corso.');

        $errorSummary = $failedStatements > 0
            ? sprintf('%s statement SQL hanno restituito errore; verificare il log del job.', number_format($failedStatements, 0, ',', '.'))
            : null;
        if ($skippedStatements > 0) {
            $skipSummary = sprintf('%s statement SQL non supportati sono stati ignorati.', number_format($skippedStatements, 0, ',', '.'));
            $errorSummary = $errorSummary !== null ? $errorSummary . ' ' . $skipSummary : $skipSummary;
        }

        $pdo->prepare("UPDATE ade_import_jobs
            SET status = 'completed',
                completed_at = NOW(),
                estimated_completion_at = NOW(),
                error_message = :error_message,
                total_comuni = :total_comuni,
                processed_comuni = :processed_comuni,
                total_particelle = :total_particelle,
                processed_particelle = :processed_particelle
            WHERE id = :id")
            ->execute([
                'error_message' => $errorSummary,
                'total_comuni' => $stats['total_comuni'],
                'processed_comuni' => $stats['processed_comuni'],
                'total_particelle' => $stats['total_particelle'],
                'processed_particelle' => $stats['processed_particelle'],
                'id' => $jobId,
            ]);

        analyticspro_ade_log(
            $jobId,
            ($failedStatements > 0 || $skippedStatements > 0) ? 'warning' : 'info',
            ($failedStatements > 0 || $skippedStatements > 0)
                ? 'Import SQL completato con errori isolati o statement ignorati.'
                : 'Import SQL completato con successo.'
        );
    } catch (Throwable $exception) {
        analyticspro_ade_log($jobId, 'error', $exception->getMessage());
        $pdo->prepare("UPDATE ade_import_jobs SET status = 'failed', error_message = :error_message, completed_at = NOW() WHERE id = :id")
            ->execute([
                'error_message' => $exception->getMessage(),
                'id' => $jobId,
            ]);
        throw $exception;
    }
}

if (PHP_SAPI === 'cli' && basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'ade_sql_import_worker.php') {
    $jobId = isset($argv[1]) ? (int) $argv[1] : 0;
    $sqlPath = $argv[2] ?? '';
    if ($jobId > 0 && is_file($sqlPath)) {
        analyticspro_run_ade_sql_import_job($jobId, $sqlPath);
        return;
    }

    if ($jobId > 0) {
        analyticspro_db()->prepare("UPDATE ade_import_jobs SET status = 'failed', error_message = :error_message, completed_at = NOW() WHERE id = :id")
            ->execute([
                'error_message' => 'Argomenti worker SQL non validi o file non trovato.',
                'id' => $jobId,
            ]);
    }

    fwrite(STDERR, "Invalid ADE SQL worker arguments.\n");
    exit(1);
}
