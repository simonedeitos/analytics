<?php

declare(strict_types=1);

function analyticspro_gml_index_log(PDO $pdo, int $jobId, string $level, string $message): void
{
    $pdo->prepare('INSERT INTO gml_index_job_log (job_id, level, message) VALUES (:job_id, :level, :message)')
        ->execute(['job_id' => $jobId, 'level' => $level, 'message' => $message]);
}

function analyticspro_gml_index_job(PDO $pdo, int $jobId): array
{
    $stmt = $pdo->prepare('SELECT * FROM gml_index_jobs WHERE id = :id');
    $stmt->execute(['id' => $jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$job) {
        throw new RuntimeException('Job non trovato.');
    }
    return $job;
}

function analyticspro_gml_index_fail(PDO $pdo, int $jobId, string $message): void
{
    $pdo->prepare("UPDATE gml_index_jobs SET status = 'failed', completed_at = NOW(), error_message = :err WHERE id = :id")
        ->execute(['err' => $message, 'id' => $jobId]);
    analyticspro_gml_index_log($pdo, $jobId, 'error', $message);
}

/**
 * Elabora un comune. Il lock di connessione serializza anche job diversi che
 * scrivono gli stessi indici SQLite e viene rilasciato se PHP termina.
 */
function analyticspro_gml_index_step(PDO $pdo, int $jobId, float $maxSeconds = 20.0): array
{
    $job = analyticspro_gml_index_job($pdo, $jobId);
    if (in_array($job['status'], ['completed', 'failed'], true)) {
        return ['done' => true, 'job' => $job];
    }

    $lockName = 'analyticspro_gml_index';
    $lock = $pdo->prepare('SELECT GET_LOCK(:name, 0)');
    $lock->execute(['name' => $lockName]);
    $acquired = $lock->fetchColumn();
    if ($acquired === null || $acquired === false) {
        throw new RuntimeException('Lock di indicizzazione non disponibile.');
    }
    if ((int) $acquired !== 1) {
        return ['done' => false, 'busy' => true, 'job' => $job];
    }

    $active = false;
    register_shutdown_function(static function () use ($pdo, $jobId, &$active): void {
        if (!$active) {
            return;
        }
        $error = error_get_last();
        if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            try {
                analyticspro_gml_index_fail($pdo, $jobId, 'Elaborazione interrotta dal server (timeout o risorse esaurite).');
            } catch (Throwable $e) {
                error_log('[gml_index_worker] ' . $e->getMessage());
            }
        }
    });

    try {
        // Rileggi dopo il lock: un'altra richiesta potrebbe aver completato il job.
        $job = analyticspro_gml_index_job($pdo, $jobId);
        if (in_array($job['status'], ['completed', 'failed'], true)) {
            return ['done' => true, 'job' => $job];
        }
        $active = true;
        $catalog = analyticspro_gml_build_catalog();
        if ($job['comune_codes'] === null) {
            $codes = strtoupper($job['belfiore']) === 'ALL'
                ? array_keys($catalog)
                : array_filter(array_map('trim', explode(',', strtoupper($job['belfiore']))));
            $codes = array_values(array_unique($codes));
            sort($codes, SORT_STRING);
            $pdo->prepare("UPDATE gml_index_jobs SET status = 'running', started_at = COALESCE(started_at, NOW()), comune_codes = :codes, total_comuni = :n WHERE id = :id")
                ->execute(['codes' => json_encode($codes, JSON_THROW_ON_ERROR), 'n' => count($codes), 'id' => $jobId]);
            analyticspro_gml_index_log($pdo, $jobId, 'info', 'Worker avviato per job #' . $jobId . '. Comuni da indicizzare: ' . count($codes) . '.');
            $job = analyticspro_gml_index_job($pdo, $jobId);
        } else {
            $codes = json_decode($job['comune_codes'], true, 512, JSON_THROW_ON_ERROR);
        }

        $processed = (int) $job['processed_comuni'];
        $parcels = (int) $job['committed_particelle'];
        $errors = (int) $job['failed_comuni'];
        if ($processed < count($codes)) {
            $belfiore = $codes[$processed];
            $entry = $catalog[$belfiore] ?? null;
            $state = $job['parcel_state'] !== null ? json_decode($job['parcel_state'], true, 512, JSON_THROW_ON_ERROR) : null;
            try {
                if ($entry === null || empty($entry['ple'])) {
                    throw new RuntimeException('File _ple.gml non trovato.');
                }
                if ($state === null) {
                    analyticspro_gml_index_log($pdo, $jobId, 'info', 'Inizio indicizzazione ' . $belfiore . ' (' . ($entry['nome'] ?? $belfiore) . ')…');
                }
                $n = analyticspro_gml_build_parcel_index($belfiore, static function (int $done, int $total) use ($pdo, $jobId, $parcels, $belfiore): void {
                    $pdo->prepare('UPDATE gml_index_jobs SET processed_particelle = :p, total_particelle = :total WHERE id = :id')
                        ->execute(['p' => $parcels + $done, 'total' => $parcels + max($total, $done), 'id' => $jobId]);
                    analyticspro_gml_index_log($pdo, $jobId, 'info', $belfiore . ': ' . $done . ' particelle elaborate.');
                }, $state, $maxSeconds, $jobId);
                if ($state !== null) {
                    $pdo->prepare('UPDATE gml_index_jobs SET parcel_state = :state WHERE id = :id')
                        ->execute(['state' => json_encode($state, JSON_THROW_ON_ERROR), 'id' => $jobId]);
                    return ['done' => false, 'job' => analyticspro_gml_index_job($pdo, $jobId)];
                }
                $parcels += $n;
                analyticspro_gml_index_log($pdo, $jobId, 'info', $belfiore . ': ' . $n . ' particelle indicizzate.');
            } catch (Throwable $e) {
                $errors++;
                error_log('[gml_index_worker] ' . $belfiore . ': ' . $e->getMessage());
                analyticspro_gml_index_log($pdo, $jobId, 'error', $belfiore . ': indicizzazione non riuscita (file mancante, errore di lettura o limite di tempo). Consultare il log PHP del server.');
            }
            $processed++;
            $pdo->prepare('UPDATE gml_index_jobs SET processed_comuni = :c, total_particelle = :p, processed_particelle = :pp, committed_particelle = :committed, failed_comuni = :errors, parcel_state = NULL WHERE id = :id')
                ->execute(['c' => $processed, 'p' => $parcels, 'pp' => $parcels, 'committed' => $parcels, 'errors' => $errors, 'id' => $jobId]);
        }

        if ($processed >= count($codes)) {
            $message = 'Indicizzazione terminata: ' . $processed . '/' . count($codes) . ' comuni, ' . $parcels . ' particelle, ' . $errors . ' errori.';
            if ($errors > 0) {
                analyticspro_gml_index_fail($pdo, $jobId, $message);
            } else {
                $pdo->prepare("UPDATE gml_index_jobs SET status = 'completed', completed_at = NOW() WHERE id = :id")
                    ->execute(['id' => $jobId]);
                analyticspro_gml_index_log($pdo, $jobId, 'info', $message);
            }
        }
        $job = analyticspro_gml_index_job($pdo, $jobId);
        return ['done' => in_array($job['status'], ['completed', 'failed'], true), 'job' => $job];
    } catch (Throwable $e) {
        error_log('[gml_index_worker] ' . $e->getMessage());
        analyticspro_gml_index_fail($pdo, $jobId, 'Errore durante l’indicizzazione. Verificare la migration 019 e il log PHP del server.');
        return ['done' => true, 'job' => analyticspro_gml_index_job($pdo, $jobId)];
    } finally {
        $active = false;
        $pdo->prepare('SELECT RELEASE_LOCK(:name)')->execute(['name' => $lockName]);
    }
}
