<?php

declare(strict_types=1);

function analyticspro_ade_log(int $jobId, string $level, string $message): void
{
    analyticspro_db()->prepare('INSERT INTO ade_import_job_log (job_id, level, message) VALUES (:job_id, :level, :message)')
        ->execute([
            'job_id' => $jobId,
            'level' => $level,
            'message' => $message,
        ]);
}

function analyticspro_update_ade_job_progress(int $jobId, array $stats, ?string $status = null): void
{
    $sql = 'UPDATE ade_import_jobs
            SET total_comuni = :total_comuni,
                processed_comuni = :processed_comuni,
                total_particelle = :total_particelle,
                processed_particelle = :processed_particelle';

    $params = [
        'total_comuni' => (int) ($stats['total_comuni'] ?? 0),
        'processed_comuni' => (int) ($stats['processed_comuni'] ?? 0),
        'total_particelle' => (int) ($stats['total_particelle'] ?? 0),
        'processed_particelle' => (int) ($stats['processed_particelle'] ?? 0),
        'id' => $jobId,
    ];

    if ($status !== null) {
        $sql .= ', status = :status';
        $params['status'] = $status;
    }

    $sql .= ' WHERE id = :id';
    analyticspro_db()->prepare($sql)->execute($params);
}

function analyticspro_ade_guess_job_type(string $filename): string
{
    $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    return $extension === 'sql' ? 'sql' : 'zip';
}

function analyticspro_ade_create_job(PDO $pdo, string $filename, int $createdBy): int
{
    $jobType = analyticspro_ade_guess_job_type($filename);
    $provinciaSigla = $jobType === 'sql'
        ? 'SQL'
        : strtoupper(pathinfo($filename, PATHINFO_FILENAME));

    $pdo->prepare(
        "INSERT INTO ade_import_jobs (provincia_sigla, zip_filename, status, created_by)
         VALUES (:provincia_sigla, :zip_filename, 'queued', :created_by)"
    )->execute([
        'provincia_sigla' => $provinciaSigla,
        'zip_filename' => basename($filename),
        'created_by' => $createdBy,
    ]);

    return (int) $pdo->lastInsertId();
}

function analyticspro_ade_validate_filename(string $rawName, array $allowedExtensions): ?string
{
    $name = basename($rawName);
    if ($name === '') {
        return null;
    }

    $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return in_array($extension, $allowedExtensions, true) ? $name : null;
}

function analyticspro_ade_prepare_destination_path(int $jobId, string $filename): string
{
    return ANALYTICSPRO_ROOT . '/storage/ade_uploads/job_' . $jobId . '_' . basename($filename);
}

function analyticspro_ade_is_path_within_directory(string $path, string $directory): bool
{
    $realPath = realpath($path);
    $realDirectory = realpath($directory);
    if ($realPath === false || $realDirectory === false) {
        return false;
    }

    return $realPath === $realDirectory || str_starts_with($realPath, $realDirectory . DIRECTORY_SEPARATOR);
}

function analyticspro_ade_is_allowed_import_path(string $path, array $allowedExtensions = ['zip', 'sql']): bool
{
    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if (!in_array($extension, $allowedExtensions, true)) {
        return false;
    }

    $allowedDirectories = [
        ANALYTICSPRO_ROOT . '/storage/manual_upload',
        ANALYTICSPRO_ROOT . '/storage/ade_uploads',
    ];

    foreach ($allowedDirectories as $directory) {
        if (analyticspro_ade_is_path_within_directory($path, $directory)) {
            return true;
        }
    }

    return false;
}

function analyticspro_ade_worker_script_for_type(string $jobType): string
{
    return match ($jobType) {
        'sql' => ANALYTICSPRO_ROOT . '/cron/ade_sql_import_worker.php',
        default => ANALYTICSPRO_ROOT . '/cron/ade_import_worker.php',
    };
}

function analyticspro_ade_launch_job(int $jobId, string $sourcePath, string $jobType): bool
{
    $worker = analyticspro_ade_worker_script_for_type($jobType);
    if (analyticspro_launch_background($worker, [$jobId, $sourcePath])) {
        return true;
    }

    $kind = $jobType === 'sql' ? 'file SQL pre-elaborato' : 'archivio ZIP ADE';
    analyticspro_ade_log(
        $jobId,
        'error',
        'Impossibile avviare il worker in background per ' . $kind
        . ' (proc_open/shell_exec non disponibili su questo hosting). Contattare l\'amministratore di sistema '
        . 'per abilitare l\'esecuzione di processi in background, oppure configurare un cron job che esegua manualmente: php '
        . $worker . ' ' . $jobId . ' ' . $sourcePath
    );

    analyticspro_db()->prepare("UPDATE ade_import_jobs SET status = 'failed', error_message = :error_message WHERE id = :id")
        ->execute([
            'error_message' => 'Impossibile avviare il worker in background su questo hosting.',
            'id' => $jobId,
        ]);

    return false;
}

function analyticspro_ade_analyze_sql_file(string $sqlPath): array
{
    $stats = [
        'total_statements' => 0,
        'total_comuni' => 0,
        'total_particelle' => 0,
        'total_other' => 0,
    ];

    analyticspro_ade_stream_sql_statements($sqlPath, static function (string $statement) use (&$stats): void {
        $stats['total_statements']++;
        $type = analyticspro_ade_classify_sql_statement($statement);
        if ($type === 'comune') {
            $stats['total_comuni']++;
        } elseif ($type === 'parcel') {
            $stats['total_particelle']++;
        } else {
            $stats['total_other']++;
        }
    });

    return $stats;
}

function analyticspro_ade_classify_sql_statement(string $statement): string
{
    $normalized = ltrim($statement);
    $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

    if (preg_match('/^INSERT INTO cadastral_comuni\b/i', $normalized)) {
        return 'comune';
    }

    if (preg_match('/^INSERT INTO cadastral_parcels\b/i', $normalized)) {
        return 'parcel';
    }

    return 'other';
}

function analyticspro_ade_stream_sql_statements(string $sqlPath, callable $callback): void
{
    if (!is_file($sqlPath) || !is_readable($sqlPath)) {
        throw new RuntimeException('File SQL non leggibile: ' . $sqlPath);
    }

    $handle = fopen($sqlPath, 'rb');
    if ($handle === false) {
        throw new RuntimeException('Impossibile aprire il file SQL: ' . basename($sqlPath));
    }

    $buffer = '';
    $inSingleQuote = false;
    $inDoubleQuote = false;
    $inLineComment = false;
    $inBlockComment = false;

    try {
        while (($line = fgets($handle)) !== false) {
            $length = strlen($line);

            for ($index = 0; $index < $length; $index++) {
                $char = $line[$index];
                $next = $index + 1 < $length ? $line[$index + 1] : '';

                if ($inLineComment) {
                    if ($char === "\n") {
                        $inLineComment = false;
                    }
                    continue;
                }

                if ($inBlockComment) {
                    if ($char === '*' && $next === '/') {
                        $inBlockComment = false;
                        $index++;
                    }
                    continue;
                }

                if ($inSingleQuote) {
                    $buffer .= $char;
                    if ($char === "'" && $next === "'") {
                        $buffer .= $next;
                        $index++;
                        continue;
                    }

                    if ($char === "'" && $next !== "'") {
                        $inSingleQuote = false;
                    }
                    continue;
                }

                if ($inDoubleQuote) {
                    $buffer .= $char;
                    if ($char === '"' && $next === '"') {
                        $buffer .= $next;
                        $index++;
                        continue;
                    }

                    if ($char === '"' && $next !== '"') {
                        $inDoubleQuote = false;
                    }
                    continue;
                }

                if ($char === '-' && $next === '-' && analyticspro_ade_is_sql_line_comment_start($line, $index)) {
                    $inLineComment = true;
                    $index++;
                    continue;
                }

                if ($char === '#') {
                    $inLineComment = true;
                    continue;
                }

                if ($char === '/' && $next === '*') {
                    $inBlockComment = true;
                    $index++;
                    continue;
                }

                if ($char === "'") {
                    $inSingleQuote = true;
                    $buffer .= $char;
                    continue;
                }

                if ($char === '"') {
                    $inDoubleQuote = true;
                    $buffer .= $char;
                    continue;
                }

                if ($char === ';') {
                    $statement = trim($buffer);
                    if ($statement !== '') {
                        $callback($statement);
                    }
                    $buffer = '';
                    continue;
                }

                $buffer .= $char;
            }
        }

        $statement = trim($buffer);
        if ($statement !== '') {
            $callback($statement);
        }
    } finally {
        fclose($handle);
    }
}

function analyticspro_ade_is_sql_line_comment_start(string $line, int $index): bool
{
    // "--" seguito da spazio/tab/newline oppure fine riga è un commento SQL valido.
    $third = $line[$index + 2] ?? '';
    return $third === '' || ctype_space($third);
}
