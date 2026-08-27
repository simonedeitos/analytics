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

function analyticspro_ade_collect_warning(array &$warningSummary, string $key, ?string $example = null, ?string $detail = null): void
{
    if (!isset($warningSummary[$key])) {
        $warningSummary[$key] = [
            'count' => 0,
            'examples' => [],
            'detail' => $detail,
        ];
    }

    $warningSummary[$key]['count']++;
    if ($detail !== null && $detail !== '') {
        $warningSummary[$key]['detail'] = $detail;
    }

    if ($example === null || $example === '') {
        return;
    }

    if (!in_array($example, $warningSummary[$key]['examples'], true) && count($warningSummary[$key]['examples']) < 5) {
        $warningSummary[$key]['examples'][] = $example;
    }
}

function analyticspro_ade_warning_examples(array $examples): string
{
    return $examples === [] ? '' : ' (esempi: ' . implode(', ', $examples) . ')';
}

function analyticspro_ade_flush_warning_summary(int $jobId, string $codCatastale, string $nomeComune, array $warningSummary): void
{
    foreach ($warningSummary as $key => $entry) {
        $count = (int) ($entry['count'] ?? 0);
        if ($count <= 0) {
            continue;
        }

        $formattedCount = number_format($count, 0, ',', '.');
        $examples = analyticspro_ade_warning_examples($entry['examples'] ?? []);

        switch ($key) {
            case 'unparseable_reference':
                $message = "{$formattedCount} particelle scartate nel comune {$codCatastale} ({$nomeComune}) per riferimento catastale non parsabile{$examples}.";
                break;
            case 'invalid_polygon':
                $message = "{$formattedCount} particelle scartate nel comune {$codCatastale} ({$nomeComune}) per poligono non valido{$examples}.";
                break;
            case 'invalid_polygon_wkt':
                $message = "{$formattedCount} particelle scartate nel comune {$codCatastale} ({$nomeComune}) per WKT poligono non valido{$examples}.";
                break;
            case 'missing_interior_point':
                $message = "{$formattedCount} particelle scartate nel comune {$codCatastale} ({$nomeComune}) perché impossibile calcolare l'interior point{$examples}.";
                break;
            case 'invalid_interior_point_wkt':
                $message = "{$formattedCount} particelle scartate nel comune {$codCatastale} ({$nomeComune}) per WKT interior point non valido{$examples}.";
                break;
            default:
                if (str_starts_with($key, 'interior_point_fallback:')) {
                    $strategy = (string) ($entry['detail'] ?? 'fallback');
                    $message = "{$formattedCount} particelle nel comune {$codCatastale} ({$nomeComune}) usano fallback interior point ({$strategy}){$examples}.";
                } elseif (str_starts_with($key, 'parcel_exception:')) {
                    $detail = (string) ($entry['detail'] ?? 'errore sconosciuto');
                    $message = "{$formattedCount} particelle scartate nel comune {$codCatastale} ({$nomeComune}) per errore in import: {$detail}{$examples}.";
                } else {
                    $message = "{$formattedCount} warning aggregati nel comune {$codCatastale} ({$nomeComune}){$examples}.";
                }
                break;
        }

        analyticspro_ade_log($jobId, 'warning', $message);
    }
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
        if (!preg_match('/_ple\.gml$/i', $filename)) {
            continue;
        }

        $fullPath = $fileInfo->getPathname();
        $baseWithoutSuffix = preg_replace('/_ple\.gml$/i', '', $filename) ?? '';
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

function analyticspro_log_ignored_map_gml_files(int $jobId, string $jobDir): void
{
    if (!is_dir($jobDir)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($jobDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
            continue;
        }

        $filename = $fileInfo->getFilename();
        if (!preg_match('/_map\.gml$/i', $filename)) {
            continue;
        }

        analyticspro_ade_log(
            $jobId,
            'info',
            "File _map.gml rilevato (" . $filename . ') — contiene fogli/sezioni (CadastralZoning), ignorato per import particelle.'
        );
    }
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
        analyticspro_log_ignored_map_gml_files($jobId, $jobDir);
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
            "INSERT INTO cadastral_parcels (comune_id, cod_catastale, sezione, foglio, particella, geom, interior_point, area_mq, source_file)
             VALUES (:comune_id, :cod_catastale, :sezione, :foglio, :particella, ST_GeomFromText(:wkt_polygon, 4326), ST_GeomFromText(:wkt_point, 4326), :area_mq, :source_file)
             ON DUPLICATE KEY UPDATE
                id = LAST_INSERT_ID(id),
                geom = VALUES(geom),
                interior_point = VALUES(interior_point),
                area_mq = VALUES(area_mq),
                source_file = VALUES(source_file)"
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

            $warningSummary = [];
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
                if ($comuneId <= 0) {
                    throw new RuntimeException("Impossibile risolvere comune_id per {$codCatastale} ({$nomeComune}).");
                }
                $insertedForComune = 0;

                foreach ($parcels as $parcel) {
                    $parts = analyticspro_extract_cadastral_parts($parcel['national_reference'] ?? null, $parcel['label'] ?? null);
                    if ($parts === null) {
                        $reference = trim((string) ($parcel['national_reference'] ?? ''));
                        $label = trim((string) ($parcel['label'] ?? ''));
                        $example = $reference !== '' ? $reference : ($label !== '' ? 'label=' . $label : null);
                        analyticspro_ade_collect_warning($warningSummary, 'unparseable_reference', $example);
                        continue;
                    }

                    $polygonPoints = $parcel['points'] ?? [];
                    if (!is_array($polygonPoints) || count($polygonPoints) < 4) {
                        analyticspro_ade_collect_warning($warningSummary, 'invalid_polygon', "{$parts['foglio']}/{$parts['particella']}");
                        continue;
                    }

                    $wktPolygon = analyticspro_polygon_to_wkt($polygonPoints);
                    if ($wktPolygon === null) {
                        analyticspro_ade_collect_warning($warningSummary, 'invalid_polygon_wkt', "{$parts['foglio']}/{$parts['particella']}");
                        continue;
                    }

                    $interior = analyticspro_compute_polygon_interior_point($polygonPoints);
                    $interiorPoint = $interior['point'] ?? null;
                    if (!is_array($interiorPoint)) {
                        analyticspro_ade_collect_warning($warningSummary, 'missing_interior_point', "{$parts['foglio']}/{$parts['particella']}");
                        continue;
                    }

                    $wktPoint = analyticspro_point_to_wkt($interiorPoint);
                    if ($wktPoint === null) {
                        analyticspro_ade_collect_warning($warningSummary, 'invalid_interior_point_wkt', "{$parts['foglio']}/{$parts['particella']}");
                        continue;
                    }

                    if (($interior['inside'] ?? false) !== true) {
                        $strategy = (string) ($interior['strategy'] ?? 'fallback');
                        analyticspro_ade_collect_warning($warningSummary, 'interior_point_fallback:' . $strategy, "{$parts['foglio']}/{$parts['particella']}", $strategy);
                    }

                    try {
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
                    } catch (Throwable $parcelException) {
                        $detail = $parcelException->getMessage();
                        analyticspro_ade_collect_warning(
                            $warningSummary,
                            'parcel_exception:' . md5($detail),
                            "{$parts['foglio']}/{$parts['particella']}",
                            $detail
                        );
                        continue;
                    }

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
                analyticspro_ade_flush_warning_summary($jobId, $codCatastale, $nomeComune, $warningSummary);

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
                analyticspro_ade_flush_warning_summary($jobId, $codCatastale, $nomeComune, $warningSummary);
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
