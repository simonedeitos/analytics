<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/gml_parser.php';

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
        }
    }
    $zip->close();
}

function analyticspro_discover_comune_gml_sets(string $jobDir): array
{
    if (!is_dir($jobDir)) {
        return [];
    }

    $pleFiles = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($jobDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
            continue;
        }

        $filename = $fileInfo->getFilename();
        if (!preg_match('/_(ple)\.gml$/i', $filename)) {
            continue;
        }

        $fullPath = $fileInfo->getPathname();
        $baseWithoutSuffix = preg_replace('/_(ple)\.gml$/i', '', $filename) ?? '';
        if ($baseWithoutSuffix === '') {
            continue;
        }

        $parts = explode('_', $baseWithoutSuffix, 2);
        if (count($parts) !== 2) {
            continue;
        }

        $codCatastale = strtoupper(trim($parts[0]));
        $nomeComune = trim(str_replace('_', ' ', $parts[1]));

        $mapPath = $fileInfo->getPath() . '/' . $baseWithoutSuffix . '_map.gml';

        $pleFiles[] = [
            'cod_catastale' => $codCatastale,
            'nome_comune' => $nomeComune,
            'ple_path' => $fullPath,
            'map_path' => is_file($mapPath) ? $mapPath : null,
            'ple_filename' => $filename,
            'map_filename' => is_file($mapPath) ? basename($mapPath) : null,
        ];
    }

    usort($pleFiles, static fn(array $a, array $b): int => strcmp((string) $a['cod_catastale'], (string) $b['cod_catastale']));

    return $pleFiles;
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

function analyticspro_run_ade_import_job(int $jobId, string $zipPath): void
{
    if (PHP_SAPI === 'cli' && function_exists('set_time_limit')) {
        @set_time_limit(0);
    }

    $pdo = analyticspro_db();
    $pdo->prepare("UPDATE ade_import_jobs SET status = 'extracting', started_at = NOW() WHERE id = :id")->execute(['id' => $jobId]);
    analyticspro_ade_log($jobId, 'info', 'Job ADE avviato.');

    $jobDir = ANALYTICSPRO_ROOT . '/storage/ade_jobs/job_' . $jobId;
    if (!is_dir($jobDir)) {
        mkdir($jobDir, 0775, true);
    }

    $jobRow = $pdo->prepare('SELECT provincia_sigla FROM ade_import_jobs WHERE id = :id LIMIT 1');
    $jobRow->execute(['id' => $jobId]);
    $provinciaSigla = strtoupper((string) ($jobRow->fetchColumn() ?: ''));

    $stats = [
        'total_comuni' => 0,
        'processed_comuni' => 0,
        'total_particelle' => 0,
        'processed_particelle' => 0,
    ];

    try {
        analyticspro_extract_nested_zip($jobId, $zipPath, $jobDir, $stats);

        $comuneSets = analyticspro_discover_comune_gml_sets($jobDir);
        $stats['total_comuni'] = count($comuneSets);
        $stats['processed_comuni'] = 0;

        analyticspro_update_ade_job_progress($jobId, $stats, 'importing');
        analyticspro_ade_log($jobId, 'info', 'GML parsing in corso: import su tabelle catastali MySQL.');

        $upsertComuneStmt = $pdo->prepare(
            'INSERT INTO cadastral_comuni (provincia_sigla, cod_catastale, nome_comune, ade_import_job_id, map_gml_filename, ple_gml_filename)
             VALUES (:provincia_sigla, :cod_catastale, :nome_comune, :ade_import_job_id, :map_gml_filename, :ple_gml_filename)
             ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                nome_comune = VALUES(nome_comune),
                ade_import_job_id = VALUES(ade_import_job_id),
                map_gml_filename = VALUES(map_gml_filename),
                ple_gml_filename = VALUES(ple_gml_filename),
                updated_at = NOW()'
        );

        $upsertParcelStmt = $pdo->prepare(
            'INSERT INTO cadastral_parcels (comune_id, cod_catastale, sezione, foglio, particella, geom, interior_point, area_mq, source_file)
             VALUES (:comune_id, :cod_catastale, :sezione, :foglio, :particella, ST_GeomFromText(:wkt_polygon, 4326), ST_GeomFromText(:wkt_point, 4326), :area_mq, :source_file)
             ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                geom = VALUES(geom),
                interior_point = VALUES(interior_point),
                area_mq = VALUES(area_mq),
                source_file = VALUES(source_file)'
        );

        $upsertVerificationStmt = $pdo->prepare(
            "INSERT INTO cadastral_parcel_verification (parcel_id, metodo, verificato, tentativi)
             VALUES (:parcel_id, 'interior_point', 0, 0)
             ON DUPLICATE KEY UPDATE metodo = VALUES(metodo)"
        );

        $progressStep = 50;
        foreach ($comuneSets as $comuneSet) {
            $codCatastale = (string) $comuneSet['cod_catastale'];
            $nomeComune = (string) $comuneSet['nome_comune'];
            $plePath = (string) $comuneSet['ple_path'];
            $mapPath = $comuneSet['map_path'];

            if (!$mapPath) {
                analyticspro_ade_log($jobId, 'warning', "File MAP mancante per {$codCatastale} ({$nomeComune}); procedo con PLE.");
            }

            try {
                $parcels = analyticspro_parse_cadastral_parcels_gml($plePath);
            } catch (Throwable $exception) {
                analyticspro_ade_log($jobId, 'warning', "GML non analizzabile per {$codCatastale} ({$nomeComune}): {$exception->getMessage()}");
                $stats['processed_comuni']++;
                analyticspro_update_ade_job_progress($jobId, $stats);
                continue;
            }

            $stats['total_particelle'] += count($parcels);
            analyticspro_update_ade_job_progress($jobId, $stats);

            try {
                $pdo->beginTransaction();

                $upsertComuneStmt->execute([
                    'provincia_sigla' => $provinciaSigla,
                    'cod_catastale' => $codCatastale,
                    'nome_comune' => $nomeComune,
                    'ade_import_job_id' => $jobId,
                    'map_gml_filename' => is_string($comuneSet['map_filename']) ? $comuneSet['map_filename'] : null,
                    'ple_gml_filename' => $comuneSet['ple_filename'],
                ]);

                $comuneId = (int) $pdo->lastInsertId();
                $insertedForComune = 0;

                foreach ($parcels as $parcel) {
                    $parts = analyticspro_extract_cadastral_parts($parcel['national_reference'] ?? null, $parcel['label'] ?? null);
                    if ($parts === null) {
                        $label = (string) ($parcel['label'] ?? 'N/D');
                        $reference = (string) ($parcel['national_reference'] ?? 'N/D');
                        analyticspro_ade_log($jobId, 'warning', "Riferimento catastale non parsabile ({$codCatastale}): label={$label}, ref={$reference}");
                        continue;
                    }

                    $polygonPoints = $parcel['points'] ?? [];
                    if (!is_array($polygonPoints) || count($polygonPoints) < 4) {
                        analyticspro_ade_log($jobId, 'warning', "Poligono non valido saltato per {$codCatastale} {$parts['foglio']}/{$parts['particella']}");
                        continue;
                    }

                    $wktPolygon = analyticspro_polygon_to_wkt($polygonPoints);
                    if ($wktPolygon === null) {
                        analyticspro_ade_log($jobId, 'warning', "WKT poligono non valido per {$codCatastale} {$parts['foglio']}/{$parts['particella']}");
                        continue;
                    }

                    $interior = analyticspro_compute_polygon_interior_point($polygonPoints);
                    $interiorPoint = $interior['point'] ?? null;
                    if (!is_array($interiorPoint)) {
                        analyticspro_ade_log($jobId, 'warning', "Impossibile calcolare interior point per {$codCatastale} {$parts['foglio']}/{$parts['particella']}");
                        continue;
                    }

                    $wktPoint = analyticspro_point_to_wkt($interiorPoint);
                    if ($wktPoint === null) {
                        analyticspro_ade_log($jobId, 'warning', "WKT interior point non valido per {$codCatastale} {$parts['foglio']}/{$parts['particella']}");
                        continue;
                    }

                    if (($interior['inside'] ?? false) !== true) {
                        analyticspro_ade_log($jobId, 'warning', "Centroide fuori poligono, uso fallback {$interior['strategy']} per {$codCatastale} {$parts['foglio']}/{$parts['particella']}");
                    }

                    $upsertParcelStmt->execute([
                        'comune_id' => $comuneId,
                        'cod_catastale' => $codCatastale,
                        'sezione' => $parts['sezione'] ?? null,
                        'foglio' => $parts['foglio'],
                        'particella' => $parts['particella'],
                        'wkt_polygon' => $wktPolygon,
                        'wkt_point' => $wktPoint,
                        'area_mq' => $parcel['area_mq'] ?? null,
                        'source_file' => basename($plePath),
                    ]);

                    $parcelId = (int) $pdo->lastInsertId();
                    $upsertVerificationStmt->execute([
                        'parcel_id' => $parcelId,
                    ]);

                    // TODO: integrare verifica reale contro servizio ADE (WMS/AJAX) in una fase successiva.

                    $stats['processed_particelle']++;
                    $insertedForComune++;

                    if ($stats['processed_particelle'] % $progressStep === 0) {
                        analyticspro_update_ade_job_progress($jobId, $stats);
                        analyticspro_ade_log(
                            $jobId,
                            'info',
                            sprintf(
                                'Importate %s particelle su %s nel comune %s (%s).',
                                number_format($stats['processed_particelle'], 0, ',', '.'),
                                number_format(max($stats['total_particelle'], 1), 0, ',', '.'),
                                $nomeComune,
                                $codCatastale
                            )
                        );
                    }
                }

                $pdo->commit();
                $stats['processed_comuni']++;
                analyticspro_update_ade_job_progress($jobId, $stats);

                analyticspro_ade_log(
                    $jobId,
                    'info',
                    sprintf(
                        'Comune importato: %s (%s), particelle inserite/aggiornate: %s.',
                        $nomeComune,
                        $codCatastale,
                        number_format($insertedForComune, 0, ',', '.')
                    )
                );
            } catch (Throwable $exception) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $stats['processed_comuni']++;
                analyticspro_update_ade_job_progress($jobId, $stats);
                analyticspro_ade_log($jobId, 'warning', "Errore import comune {$codCatastale} ({$nomeComune}): {$exception->getMessage()}");
                continue;
            }
        }

        analyticspro_update_ade_job_progress($jobId, $stats, 'verifying');

        $countComuniStmt = $pdo->prepare('SELECT COUNT(*) FROM cadastral_comuni WHERE ade_import_job_id = :job_id');
        $countComuniStmt->execute(['job_id' => $jobId]);
        $totalComuni = (int) $countComuniStmt->fetchColumn();

        $countParcelsStmt = $pdo->prepare('SELECT COUNT(*) FROM cadastral_parcels cp JOIN cadastral_comuni cc ON cc.id = cp.comune_id WHERE cc.ade_import_job_id = :job_id');
        $countParcelsStmt->execute(['job_id' => $jobId]);
        $totalParcels = (int) $countParcelsStmt->fetchColumn();

        analyticspro_ade_log($jobId, 'info', "Import completato: {$totalComuni} comuni, {$totalParcels} particelle inserite nel database.");

        $pdo->prepare("UPDATE ade_import_jobs
            SET status = 'completed',
                completed_at = NOW(),
                estimated_completion_at = NOW(),
                processed_comuni = :processed_comuni,
                processed_particelle = :processed_particelle,
                total_particelle = :total_particelle,
                total_comuni = :total_comuni
            WHERE id = :id")
            ->execute([
                'processed_comuni' => $totalComuni,
                'processed_particelle' => $totalParcels,
                'total_particelle' => max($stats['total_particelle'], $totalParcels),
                'total_comuni' => max($stats['total_comuni'], $totalComuni),
                'id' => $jobId,
            ]);
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
