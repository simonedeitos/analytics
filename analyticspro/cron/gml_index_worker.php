<?php

declare(strict_types=1);

/**
 * Worker in background per l'indicizzazione delle particelle GML.
 *
 * Uso: php analyticspro/cron/gml_index_worker.php <job_id>
 *
 * Stesso pattern di ade_import_worker.php.
 */

require dirname(__DIR__) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/gml_catalog.php';

$jobId = isset($argv[1]) ? (int) $argv[1] : 0;
if ($jobId <= 0) {
    fwrite(STDERR, "Uso: php gml_index_worker.php <job_id>\n");
    exit(1);
}

$pdo = analyticspro_db();

// Funzione helper per i log
function gml_worker_log(int $jobId, string $level, string $message): void
{
    global $pdo;
    try {
        $pdo->prepare('INSERT INTO gml_index_job_log (job_id, level, message) VALUES (:job_id, :level, :message)')
            ->execute(['job_id' => $jobId, 'level' => $level, 'message' => $message]);
    } catch (Throwable $e) {
        fwrite(STDERR, "[gml_worker] log failed: " . $e->getMessage() . "\n");
    }
}

// Leggi il job
$stmt = $pdo->prepare('SELECT * FROM gml_index_jobs WHERE id = :id LIMIT 1');
$stmt->execute(['id' => $jobId]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    fwrite(STDERR, "[gml_worker] Job #{$jobId} non trovato.\n");
    exit(1);
}

if ($job['status'] !== 'queued') {
    fwrite(STDERR, "[gml_worker] Job #{$jobId} in stato '{$job['status']}' — ignorato.\n");
    exit(0);
}

// Avvia il job
$pdo->prepare("UPDATE gml_index_jobs SET status = 'running', started_at = NOW() WHERE id = :id")
    ->execute(['id' => $jobId]);

gml_worker_log($jobId, 'info', 'Worker avviato per job #' . $jobId . ' (belfiore: ' . $job['belfiore'] . ').');

try {
    $catalog = analyticspro_gml_build_catalog();

    if (strtoupper($job['belfiore']) === 'ALL') {
        $codes = array_keys($catalog);
    } else {
        $codes = array_filter(
            array_map('strtoupper', array_map('trim', explode(',', $job['belfiore']))),
            static fn (string $c): bool => preg_match('/^[A-Z][0-9]{3}$/', $c) === 1
        );
    }

    $totalComuni = count($codes);
    $pdo->prepare("UPDATE gml_index_jobs SET total_comuni = :n WHERE id = :id")
        ->execute(['n' => $totalComuni, 'id' => $jobId]);

    gml_worker_log($jobId, 'info', 'Comuni da indicizzare: ' . $totalComuni . '.');

    $processedComuni = 0;
    $totalParticelle = 0;
    $errors = 0;

    foreach ($codes as $belfiore) {
        $entry = $catalog[$belfiore] ?? null;
        if ($entry === null || empty($entry['ple'])) {
            gml_worker_log($jobId, 'warning', $belfiore . ': file _ple.gml non trovato, saltato.');
            $errors++;
            $processedComuni++;
            continue;
        }

        $nomeCom = $entry['nome'] ?? $belfiore;
        gml_worker_log($jobId, 'info', 'Inizio indicizzazione ' . $belfiore . ' (' . $nomeCom . ')…');

        try {
            $n = analyticspro_gml_build_parcel_index($belfiore, static function (int $done, int $total) use ($jobId, $totalParticelle, $pdo): void {
                // Aggiorna il contatore di particelle processate periodicamente
                try {
                    $pdo->prepare("UPDATE gml_index_jobs SET processed_particelle = :p WHERE id = :id")
                        ->execute(['p' => $totalParticelle + $done, 'id' => $jobId]);
                } catch (Throwable $e) {
                    // Ignora errori di aggiornamento progress
                }
            });

            $totalParticelle += $n;
            $processedComuni++;
            gml_worker_log($jobId, 'info', $belfiore . ' (' . $nomeCom . '): ' . number_format($n, 0, ',', '.') . ' particelle indicizzate.');

            $pdo->prepare("UPDATE gml_index_jobs SET processed_comuni = :c, total_particelle = :p, processed_particelle = :pp WHERE id = :id")
                ->execute([
                    'c'  => $processedComuni,
                    'p'  => $totalParticelle,
                    'pp' => $totalParticelle,
                    'id' => $jobId,
                ]);
        } catch (Throwable $e) {
            $errors++;
            $processedComuni++;
            $msg = $belfiore . ': errore durante l\'indicizzazione: ' . $e->getMessage();
            gml_worker_log($jobId, 'error', $msg);
            error_log('[gml_index_worker] ' . $msg);
        }
    }

    $finalMsg = 'Indicizzazione completata: ' . $processedComuni . '/' . $totalComuni . ' comuni, '
        . number_format($totalParticelle, 0, ',', '.') . ' particelle totali'
        . ($errors > 0 ? ', ' . $errors . ' errori.' : '.');
    gml_worker_log($jobId, $errors > 0 ? 'warning' : 'info', $finalMsg);

    $pdo->prepare("UPDATE gml_index_jobs SET status = 'completed', completed_at = NOW(), total_particelle = :p, processed_particelle = :pp WHERE id = :id")
        ->execute(['p' => $totalParticelle, 'pp' => $totalParticelle, 'id' => $jobId]);
} catch (Throwable $e) {
    $msg = 'Errore fatale: ' . $e->getMessage();
    gml_worker_log($jobId, 'error', $msg);
    $pdo->prepare("UPDATE gml_index_jobs SET status = 'failed', completed_at = NOW(), error_message = :err WHERE id = :id")
        ->execute(['err' => $msg, 'id' => $jobId]);
    fwrite(STDERR, "[gml_index_worker] " . $msg . "\n");
    exit(1);
}
