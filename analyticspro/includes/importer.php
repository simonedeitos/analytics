<?php

declare(strict_types=1);

function analyticspro_parse_contacts(string $raw): array
{
    $phones = [];
    $emails = [];

    if ($raw === '') {
        return ['phones' => [], 'emails' => []];
    }

    preg_match_all('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', $raw, $emailMatches);
    $emails = array_values(array_unique(array_map('strtolower', $emailMatches[0] ?? [])));
    $noEmail = preg_replace('/[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/', ' ', $raw) ?? $raw;

    preg_match_all('/(\+39[\s\-]?)?(\b(3\d{8,9}|0\d{8,11})\b)/', $noEmail, $phoneMatches);
    foreach ($phoneMatches[0] ?? [] as $phone) {
        $normalized = preg_replace('/[\s\-\/]/', '', $phone) ?? '';
        if (strlen($normalized) >= 9) {
            $phones[] = $normalized;
        }
    }

    return [
        'phones' => array_values(array_unique($phones)),
        'emails' => $emails,
    ];
}

function analyticspro_parse_birth_date(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $formats = ['Y-m-d', 'd/m/Y', 'd-m-Y', 'd.m.Y'];
    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        if ($date instanceof DateTimeImmutable) {
            return $date->format('Y-m-d');
        }
    }

    return null;
}

function analyticspro_guess_gender(?string $cf): ?string
{
    $cf = strtoupper(trim((string) $cf));
    if (!preg_match('/^[A-Z0-9]{11,16}$/', $cf)) {
        return null;
    }

    $day = (int) substr($cf, 9, 2);
    if ($day <= 0) {
        return null;
    }

    return $day > 40 ? 'F' : 'M';
}

function analyticspro_owner_identity_signature(array $owner): string
{
    $parts = [
        analyticspro_hash($owner['nome'] ?? null),
        analyticspro_hash($owner['cognome'] ?? null),
        analyticspro_hash($owner['codice_fiscale'] ?? null),
        analyticspro_hash($owner['telefono'] ?? null),
    ];
    return implode('|', array_map(static fn ($part) => $part ?? '', $parts));
}

function analyticspro_extract_row_payload(array $row): array
{
    $contacts = analyticspro_parse_contacts((string) ($row['Contatti'] ?? $row['contatti'] ?? ''));
    $surname = trim((string) ($row['Cognome'] ?? $row['Nome'] ?? ''));
    $givenName = trim((string) ($row['Nome1'] ?? $row['Nome Proprietario'] ?? $row['NomeProprietario'] ?? ''));
    $cf = trim((string) ($row['Codice Fiscale'] ?? $row['Codice fiscale'] ?? ''));
    $provincia = strtoupper(trim((string) ($row['Provincia'] ?? '')));
    $comune = trim((string) ($row['Comune'] ?? ''));

    return [
        'property' => [
            'provincia' => $provincia,
            'comune' => $comune,
            'cod_catastale' => trim((string) ($row['Codice Catastale'] ?? analyticspro_lookup_cod_catastale($comune, $provincia) ?? '')),
            'sezione' => trim((string) ($row['Sezione'] ?? '')),
            'foglio' => trim((string) ($row['Foglio'] ?? '')),
            'particella' => trim((string) ($row['Particella'] ?? '')),
            'subalterno' => trim((string) ($row['Subalterno'] ?? '')),
            'indirizzo' => trim((string) ($row['Indirizzo'] ?? '')),
            'civico' => trim((string) ($row['Civico'] ?? '')),
            'categoria' => trim((string) ($row['Categoria'] ?? '')),
            'classe' => trim((string) ($row['Classe'] ?? '')),
            'consistenza' => trim((string) ($row['Consistenza'] ?? '')),
            'superficie' => trim((string) ($row['Superficie'] ?? '')),
            'rendita' => trim((string) ($row['Rendita'] ?? '')),
            'titolarita' => trim((string) ($row['Titolarita'] ?? $row['Titolarità'] ?? '')),
            'quota' => trim((string) ($row['Quota'] ?? '')),
        ],
        'owner' => [
            'tipo' => preg_match('/^\d{11}$/', $cf) ? 'azienda' : 'persona',
            'nome' => $givenName,
            'cognome' => $surname,
            'codice_fiscale' => $cf,
            'telefono' => $contacts['phones'][0] ?? '',
            'indirizzo' => trim((string) ($row['Indirizzo Proprietario'] ?? $row['Indirizzo'] ?? '')),
            'email' => $contacts['emails'][0] ?? '',
            'data_nascita' => analyticspro_parse_birth_date((string) ($row['Data Nascita'] ?? '')),
            'genere' => analyticspro_guess_gender($cf),
        ],
    ];
}

function analyticspro_find_conflicts(array $rows, int $tenantId): array
{
    $pdo = analyticspro_db();
    $findProperty = $pdo->prepare('SELECT id, comune, foglio, particella, subalterno FROM properties WHERE user_id = :user_id AND provincia = :provincia AND comune = :comune AND sezione <=> :sezione AND foglio = :foglio AND particella = :particella AND subalterno <=> :subalterno LIMIT 1');
    $findOwner = $pdo->prepare('SELECT * FROM property_owners WHERE property_id = :property_id AND is_current = 1 LIMIT 1');
    $conflicts = [];

    foreach ($rows as $index => $row) {
        $payload = analyticspro_extract_row_payload($row);
        $property = $payload['property'];
        if ($property['provincia'] === '' || $property['comune'] === '' || $property['foglio'] === '' || $property['particella'] === '') {
            continue;
        }

        $findProperty->execute([
            'user_id' => $tenantId,
            'provincia' => $property['provincia'],
            'comune' => $property['comune'],
            'sezione' => $property['sezione'] !== '' ? $property['sezione'] : null,
            'foglio' => $property['foglio'],
            'particella' => $property['particella'],
            'subalterno' => $property['subalterno'] !== '' ? $property['subalterno'] : null,
        ]);
        $existing = $findProperty->fetch();
        if (!$existing) {
            continue;
        }

        $findOwner->execute(['property_id' => $existing['id']]);
        $owner = $findOwner->fetch();
        if (!$owner) {
            continue;
        }

        $existingSignature = implode('|', [
            $owner['nome_hash'] ?? '',
            $owner['cognome_hash'] ?? '',
            $owner['codice_fiscale_hash'] ?? '',
            $owner['telefono_hash'] ?? '',
        ]);
        $incomingSignature = analyticspro_owner_identity_signature($payload['owner']);
        if ($existingSignature !== $incomingSignature) {
            $conflicts[] = [
                'row_index' => $index,
                'property_id' => (int) $existing['id'],
                'comune' => $existing['comune'],
                'foglio' => $existing['foglio'],
                'particella' => $existing['particella'],
                'subalterno' => $existing['subalterno'],
                'incoming_owner' => trim(($payload['owner']['cognome'] ?? '') . ' ' . ($payload['owner']['nome'] ?? '')),
            ];
        }
    }

    return $conflicts;
}

function analyticspro_lookup_cadastral_coordinates(array $property): array
{
    // Lookup parcel coordinates: tries Zornade first (if configured), then falls back
    // to the public AdE INSPIRE WFS service.  Results are cached in a local SQLite
    // database.  When both providers fail the function returns nulls so the import
    // can continue without aborting.
    static $lastWfsCallAt    = 0.0;
    static $lastZornadeCallAt = 0.0;

    require_once __DIR__ . '/wfs_lookup.php';
    require_once __DIR__ . '/zornade_lookup.php';

    $codCatastale = (string) ($property['cod_catastale'] ?? '');
    $comune       = (string) ($property['comune']       ?? '');
    $provincia    = (string) ($property['provincia']    ?? '');
    $foglio       = analyticspro_wfs_normalize_token((string) ($property['foglio']     ?? ''));
    $particella   = analyticspro_wfs_normalize_token((string) ($property['particella'] ?? ''));
    $sezione      = isset($property['sezione']) && $property['sezione'] !== '' ? (string) $property['sezione'] : null;

    // Resolve codice catastale from comune+provincia if not supplied directly.
    if ($codCatastale === '' && $comune !== '' && $provincia !== '') {
        $codCatastale = analyticspro_wfs_lookup_cod_catastale($comune, $provincia) ?? '';
    }

    if ($foglio === '' || $particella === '') {
        return ['lat' => null, 'lng' => null, 'verified' => 0];
    }

    try {
        // --- Cache check (shared SQLite) ---
        $db     = null;
        $cached = null;
        try {
            $db     = analyticspro_wfs_open_cache_db();
            $cached = $codCatastale !== ''
                ? analyticspro_wfs_get_cached_particella($db, $codCatastale, $foglio, $particella)
                : null;
            if ($cached !== null) {
                $db->close();
                $db = null;
            }
        } catch (Throwable) {
            $db = null;
        }

        if ($cached !== null && ($cached['ok'] ?? false)) {
            return [
                'lat'      => (float) $cached['lat'],
                'lng'      => (float) $cached['lng'],
                'verified' => 1,
            ];
        }

        // --- Provider 1: Zornade (primary, if API key configured) ---
        $resolvedData = null;
        if ($comune !== '' && $provincia !== '') {
            $now      = microtime(true);
            $elapsed  = $now - $lastZornadeCallAt;
            $minGap   = 0.40; // 400 ms gap → ≤ 9 000 req/h, safely within 10 000 req/h limit
            if ($lastZornadeCallAt > 0.0 && $elapsed < $minGap) {
                usleep((int) (($minGap - $elapsed) * 1_000_000));
            }
            $zornade = analyticspro_zornade_lookup_particella($comune, $provincia, $foglio, $particella, $sezione);
            $lastZornadeCallAt = microtime(true);
            if ($zornade !== null && ($zornade['ok'] ?? false)) {
                $resolvedData = $zornade;
            }
        }

        // --- Provider 2: WFS-AdE fallback ---
        if ($resolvedData === null && $codCatastale !== '') {
            $now     = microtime(true);
            $elapsed = $now - $lastWfsCallAt;
            $minGap  = 0.5;
            if ($lastWfsCallAt > 0.0 && $elapsed < $minGap) {
                usleep((int) (($minGap - $elapsed) * 1_000_000));
            }
            $wfsData       = analyticspro_wfs_query_service($codCatastale, $foglio, $particella);
            $lastWfsCallAt = microtime(true);
            if ($wfsData !== null && ($wfsData['ok'] ?? false)) {
                $resolvedData = $wfsData;
            }
        }

        // --- Persist resolved result in cache ---
        if ($resolvedData !== null && ($resolvedData['ok'] ?? false)) {
            if ($db === null) {
                try {
                    $db = analyticspro_wfs_open_cache_db();
                } catch (Throwable) {
                    $db = null;
                }
            }
            if ($db !== null) {
                $source = $resolvedData['source'] ?? 'WFS-AdE';
                if ($source === 'Zornade' && $comune !== '' && $provincia !== '') {
                    analyticspro_zornade_save_cached_particella($db, $comune, $provincia, $foglio, $particella, $resolvedData);
                } elseif ($codCatastale !== '') {
                    analyticspro_wfs_save_cached_particella($db, $codCatastale, $foglio, $particella, $resolvedData);
                }
                $db->close();
                $db = null;
            }
            return [
                'lat'      => (float) $resolvedData['lat'],
                'lng'      => (float) $resolvedData['lng'],
                'verified' => 1,
            ];
        }

        if ($db !== null) {
            $db->close();
        }
        return ['lat' => null, 'lng' => null, 'verified' => 0];
    } catch (Throwable $exception) {
        error_log('[import lookup] Failed for ' . $codCatastale . '/' . $foglio . '/' . $particella . ': ' . $exception->getMessage());
        return ['lat' => null, 'lng' => null, 'verified' => 0];
    }
}

/** @deprecated Use analyticspro_lookup_cadastral_coordinates() instead. */
function analyticspro_lookup_postgis_coordinates(array $property): array
{
    return analyticspro_lookup_cadastral_coordinates($property);
}

function analyticspro_process_import_batch_payload(int $batchId, array $payload): void
{
    $pdo = analyticspro_db();
    $rows = $payload['rows'] ?? [];
    $tenantId = (int) ($payload['tenant_id'] ?? 0);
    $uploaderId = (int) ($payload['uploaded_by'] ?? 0);
    $decisions = $payload['decisions'] ?? [];

    $findProperty = $pdo->prepare('SELECT * FROM properties WHERE user_id = :user_id AND provincia = :provincia AND comune = :comune AND sezione <=> :sezione AND foglio = :foglio AND particella = :particella AND subalterno <=> :subalterno LIMIT 1');
    $insertProperty = $pdo->prepare('INSERT INTO properties (user_id, import_batch_id, provincia, comune, cod_catastale, sezione, foglio, particella, subalterno, indirizzo, civico, categoria, classe, consistenza, superficie, rendita, titolarita, quota, lat, lng, posizione_verificata, stato, stato_personalizzato, colore_marker) VALUES (:user_id, :import_batch_id, :provincia, :comune, :cod_catastale, :sezione, :foglio, :particella, :subalterno, :indirizzo, :civico, :categoria, :classe, :consistenza, :superficie, :rendita, :titolarita, :quota, NULL, NULL, 0, :stato, :stato_personalizzato, :colore_marker)');
    // lat/lng/posizione_verificata are intentionally excluded: coordinate enrichment runs
    // asynchronously in enrich_property_coordinates.php after the batch is persisted.
    $updateProperty = $pdo->prepare('UPDATE properties SET import_batch_id = :import_batch_id, cod_catastale = :cod_catastale, indirizzo = :indirizzo, civico = :civico, categoria = :categoria, classe = :classe, consistenza = :consistenza, superficie = :superficie, rendita = :rendita, titolarita = :titolarita, quota = :quota WHERE id = :id');
    $selectCurrentOwner = $pdo->prepare('SELECT * FROM property_owners WHERE property_id = :property_id AND is_current = 1 LIMIT 1');
    $closeOwners = $pdo->prepare('UPDATE property_owners SET is_current = 0, valid_to = NOW() WHERE property_id = :property_id AND is_current = 1');
    $insertOwner = $pdo->prepare('INSERT INTO property_owners (property_id, tipo, nome_enc, cognome_enc, codice_fiscale_enc, telefono_enc, indirizzo_enc, email_enc, nome_hash, cognome_hash, codice_fiscale_hash, telefono_hash, data_nascita, genere, is_current, valid_from) VALUES (:property_id, :tipo, :nome_enc, :cognome_enc, :codice_fiscale_enc, :telefono_enc, :indirizzo_enc, :email_enc, :nome_hash, :cognome_hash, :codice_fiscale_hash, :telefono_hash, :data_nascita, :genere, 1, NOW())');
    $insertConflict = $pdo->prepare('INSERT INTO import_duplicate_conflicts (import_batch_id, property_id, action_taken, resolved_by, resolved_at) VALUES (:import_batch_id, :property_id, :action_taken, :resolved_by, NOW())');
    $updateBatch = $pdo->prepare('UPDATE import_batches SET processed_rows = :processed_rows WHERE id = :id');

    try {
        $processed = 0;
        foreach ($rows as $index => $row) {
            $entry = analyticspro_extract_row_payload($row);
            $property = $entry['property'];
            if ($property['provincia'] === '' || $property['comune'] === '' || $property['foglio'] === '' || $property['particella'] === '') {
                $processed++;
                $updateBatch->execute(['processed_rows' => $processed, 'id' => $batchId]);
                continue;
            }

            $findProperty->execute([
                'user_id' => $tenantId,
                'provincia' => $property['provincia'],
                'comune' => $property['comune'],
                'sezione' => $property['sezione'] !== '' ? $property['sezione'] : null,
                'foglio' => $property['foglio'],
                'particella' => $property['particella'],
                'subalterno' => $property['subalterno'] !== '' ? $property['subalterno'] : null,
            ]);
            $existingProperty = $findProperty->fetch();

            if ($existingProperty) {
                $propertyId = (int) $existingProperty['id'];
                $updateProperty->execute([
                    'import_batch_id' => $batchId,
                    'cod_catastale' => $property['cod_catastale'] !== '' ? $property['cod_catastale'] : null,
                    'indirizzo' => $property['indirizzo'] !== '' ? $property['indirizzo'] : null,
                    'civico' => $property['civico'] !== '' ? $property['civico'] : null,
                    'categoria' => $property['categoria'] !== '' ? $property['categoria'] : null,
                    'classe' => $property['classe'] !== '' ? $property['classe'] : null,
                    'consistenza' => $property['consistenza'] !== '' ? $property['consistenza'] : null,
                    'superficie' => $property['superficie'] !== '' ? $property['superficie'] : null,
                    'rendita' => $property['rendita'] !== '' ? $property['rendita'] : null,
                    'titolarita' => $property['titolarita'] !== '' ? $property['titolarita'] : null,
                    'quota' => $property['quota'] !== '' ? $property['quota'] : null,
                    'id' => $propertyId,
                ]);
                $selectCurrentOwner->execute(['property_id' => $propertyId]);
                $currentOwner = $selectCurrentOwner->fetch();
                $incomingSignature = analyticspro_owner_identity_signature($entry['owner']);
                $currentSignature = $currentOwner
                    ? implode('|', [$currentOwner['nome_hash'] ?? '', $currentOwner['cognome_hash'] ?? '', $currentOwner['codice_fiscale_hash'] ?? '', $currentOwner['telefono_hash'] ?? ''])
                    : '';

                if ($currentOwner && $currentSignature !== $incomingSignature) {
                    $decision = $decisions[$index] ?? 'kept_old';
                    $insertConflict->execute([
                        'import_batch_id' => $batchId,
                        'property_id' => $propertyId,
                        'action_taken' => $decision === 'updated' ? 'updated' : 'kept_old',
                        'resolved_by' => $uploaderId,
                    ]);

                    if ($decision === 'updated') {
                        $closeOwners->execute(['property_id' => $propertyId]);
                        $insertOwner->execute([
                            'property_id' => $propertyId,
                            'tipo' => $entry['owner']['tipo'],
                            'nome_enc' => analyticspro_encrypt($entry['owner']['nome']),
                            'cognome_enc' => analyticspro_encrypt($entry['owner']['cognome']),
                            'codice_fiscale_enc' => analyticspro_encrypt($entry['owner']['codice_fiscale']),
                            'telefono_enc' => analyticspro_encrypt($entry['owner']['telefono']),
                            'indirizzo_enc' => analyticspro_encrypt($entry['owner']['indirizzo']),
                            'email_enc' => analyticspro_encrypt($entry['owner']['email']),
                            'nome_hash' => analyticspro_hash($entry['owner']['nome']),
                            'cognome_hash' => analyticspro_hash($entry['owner']['cognome']),
                            'codice_fiscale_hash' => analyticspro_hash($entry['owner']['codice_fiscale']),
                            'telefono_hash' => analyticspro_hash($entry['owner']['telefono']),
                            'data_nascita' => $entry['owner']['data_nascita'],
                            'genere' => $entry['owner']['genere'],
                        ]);
                    }
                }
            } else {
                $insertProperty->execute([
                    'user_id' => $tenantId,
                    'import_batch_id' => $batchId,
                    'provincia' => $property['provincia'],
                    'comune' => $property['comune'],
                    'cod_catastale' => $property['cod_catastale'] !== '' ? $property['cod_catastale'] : null,
                    'sezione' => $property['sezione'] !== '' ? $property['sezione'] : null,
                    'foglio' => $property['foglio'],
                    'particella' => $property['particella'],
                    'subalterno' => $property['subalterno'] !== '' ? $property['subalterno'] : null,
                    'indirizzo' => $property['indirizzo'] !== '' ? $property['indirizzo'] : null,
                    'civico' => $property['civico'] !== '' ? $property['civico'] : null,
                    'categoria' => $property['categoria'] !== '' ? $property['categoria'] : null,
                    'classe' => $property['classe'] !== '' ? $property['classe'] : null,
                    'consistenza' => $property['consistenza'] !== '' ? $property['consistenza'] : null,
                    'superficie' => $property['superficie'] !== '' ? $property['superficie'] : null,
                    'rendita' => $property['rendita'] !== '' ? $property['rendita'] : null,
                    'titolarita' => $property['titolarita'] !== '' ? $property['titolarita'] : null,
                    'quota' => $property['quota'] !== '' ? $property['quota'] : null,
                    'stato' => 'da_contattare',
                    'stato_personalizzato' => null,
                    'colore_marker' => '#0d6efd',
                ]);
                $propertyId = (int) $pdo->lastInsertId();
                $insertOwner->execute([
                    'property_id' => $propertyId,
                    'tipo' => $entry['owner']['tipo'],
                    'nome_enc' => analyticspro_encrypt($entry['owner']['nome']),
                    'cognome_enc' => analyticspro_encrypt($entry['owner']['cognome']),
                    'codice_fiscale_enc' => analyticspro_encrypt($entry['owner']['codice_fiscale']),
                    'telefono_enc' => analyticspro_encrypt($entry['owner']['telefono']),
                    'indirizzo_enc' => analyticspro_encrypt($entry['owner']['indirizzo']),
                    'email_enc' => analyticspro_encrypt($entry['owner']['email']),
                    'nome_hash' => analyticspro_hash($entry['owner']['nome']),
                    'cognome_hash' => analyticspro_hash($entry['owner']['cognome']),
                    'codice_fiscale_hash' => analyticspro_hash($entry['owner']['codice_fiscale']),
                    'telefono_hash' => analyticspro_hash($entry['owner']['telefono']),
                    'data_nascita' => $entry['owner']['data_nascita'],
                    'genere' => $entry['owner']['genere'],
                ]);
            }

            $processed++;
            $updateBatch->execute(['processed_rows' => $processed, 'id' => $batchId]);
        }

        $pdo->prepare("UPDATE import_batches SET status = 'completed', completed_at = NOW(), processed_rows = total_rows, enrichment_total = (SELECT COUNT(*) FROM properties WHERE import_batch_id = :batch_id AND lat IS NULL) WHERE id = :id")->execute(['batch_id' => $batchId, 'id' => $batchId]);
    } catch (Throwable $exception) {
        $pdo->prepare("UPDATE import_batches SET status = 'failed', error_message = :message, completed_at = NOW() WHERE id = :id")
            ->execute(['message' => $exception->getMessage(), 'id' => $batchId]);
        throw $exception;
    }
}

/**
 * Enriches coordinates for all properties with lat IS NULL in a batch (or globally
 * when $batchId === 0).  Deduplicates WFS lookups by unique cadastral parcel so the
 * public AdE service is called at most once per parcel, regardless of how many owners
 * share the same land registry record.
 *
 * This function is intentionally side-effect-only: it updates `properties.lat`,
 * `properties.lng`, `properties.posizione_verificata` and logs progress in
 * `import_batches.enrichment_*` columns.  Errors for individual parcels are isolated
 * and logged without aborting the whole enrichment run.
 */
function analyticspro_enrich_batch_coordinates(int $batchId): void
{
    require_once __DIR__ . '/wfs_lookup.php';
    require_once __DIR__ . '/zornade_lookup.php';
    require_once __DIR__ . '/gml_catalog.php';

    $pdo = analyticspro_db();

    // Defaults for the finally block.
    $enrichmentStatus = 'failed';
    $processed        = 0;
    $errors           = 0;
    $errorMessage     = null;

    try {
    if ($batchId > 0) {
        $pdo->prepare("UPDATE import_batches SET enrichment_status = 'processing' WHERE id = :id")
            ->execute(['id' => $batchId]);
    }

    // Collect unique parcels needing coordinates.
    if ($batchId > 0) {
        $selectStmt = $pdo->prepare(
            'SELECT provincia, comune, cod_catastale, sezione, foglio, particella
             FROM properties
             WHERE import_batch_id = :batch_id AND lat IS NULL
             GROUP BY provincia, comune, cod_catastale, sezione, foglio, particella'
        );
        $selectStmt->execute(['batch_id' => $batchId]);
    } else {
        $selectStmt = $pdo->prepare(
            'SELECT provincia, comune, cod_catastale, sezione, foglio, particella
             FROM properties
             WHERE lat IS NULL
             GROUP BY provincia, comune, cod_catastale, sezione, foglio, particella
             LIMIT 500'
        );
        $selectStmt->execute();
    }

    $uniqueParcels = $selectStmt->fetchAll();
    $total         = count($uniqueParcels);

    if ($batchId > 0 && $total > 0) {
        $pdo->prepare('UPDATE import_batches SET enrichment_total = :total WHERE id = :id')
            ->execute(['total' => $total, 'id' => $batchId]);
    }

    // Prepared UPDATE applied to all rows sharing the same parcel.
    // coord_source column may not exist on older installations — guarded by try/catch at use site.
    if ($batchId > 0) {
        $updateStmt = $pdo->prepare(
            'UPDATE properties
             SET lat = :lat, lng = :lng, posizione_verificata = :verified, coord_source = :coord_source
             WHERE import_batch_id = :batch_id
               AND provincia = :provincia AND comune = :comune
               AND (sezione <=> :sezione)
               AND foglio = :foglio AND particella = :particella
               AND lat IS NULL'
        );
    } else {
        $updateStmt = $pdo->prepare(
            'UPDATE properties
             SET lat = :lat, lng = :lng, posizione_verificata = :verified, coord_source = :coord_source
             WHERE provincia = :provincia AND comune = :comune
               AND (sezione <=> :sezione)
               AND foglio = :foglio AND particella = :particella
               AND lat IS NULL'
        );
    }

    $updateBatchProgress = $batchId > 0
        ? $pdo->prepare('UPDATE import_batches SET enrichment_processed = :processed WHERE id = :id')
        : null;

    $lastWfsCallAt    = 0.0;
    $lastZornadeCallAt = 0.0;
    $processed        = 0;
    $errors           = 0;

    foreach ($uniqueParcels as $parcel) {
        $provincia  = (string) ($parcel['provincia']     ?? '');
        $comune     = (string) ($parcel['comune']        ?? '');
        $codCat     = (string) ($parcel['cod_catastale'] ?? '');
        $sezione    = $parcel['sezione'] !== null ? (string) $parcel['sezione'] : null;
        $foglio     = analyticspro_wfs_normalize_token((string) ($parcel['foglio']     ?? ''));
        $particella = analyticspro_wfs_normalize_token((string) ($parcel['particella'] ?? ''));

        if ($codCat === '' && $comune !== '' && $provincia !== '') {
            $codCat = analyticspro_wfs_lookup_cod_catastale($comune, $provincia) ?? '';
        }

        if ($codCat === '' || $foglio === '' || $particella === '') {
            $processed++;
            $updateBatchProgress?->execute(['processed' => $processed, 'id' => $batchId]);
            continue;
        }

        try {
            $lat        = null;
            $lng        = null;
            $verified   = 0;
            $coordSrc   = null;

            // ----------------------------------------------------------------
            // Priority 1: GML locale (offline, O(1) after indexing)
            // ----------------------------------------------------------------
            if ($codCat !== '' && $foglio !== '' && $particella !== '') {
                $gmlResult = analyticspro_gml_lookup($codCat, $foglio, $particella);
                if ($gmlResult !== null) {
                    $lat      = $gmlResult['lat'];
                    $lng      = $gmlResult['lon'];
                    $verified = 1;
                    $coordSrc = 'gml_locale';
                }
            }

            // ----------------------------------------------------------------
            // Priority 2: Cache SQLite (già noti da WFS/Zornade precedenti)
            // ----------------------------------------------------------------
            if ($coordSrc === null) {
                $cached = null;
                $db     = null;
                try {
                    $db     = analyticspro_wfs_open_cache_db();
                    $cached = analyticspro_wfs_get_cached_particella($db, $codCat, $foglio, $particella);
                    if ($cached !== null) {
                        $db->close();
                        $db = null;
                    }
                } catch (Throwable) {
                    $db = null;
                }

                if ($cached !== null && ($cached['ok'] ?? false)) {
                    $lat      = (float) $cached['lat'];
                    $lng      = (float) $cached['lng'];
                    $verified = 1;
                    $coordSrc = 'cache';
                } else {
                    // --------------------------------------------------------
                    // Priority 3: Zornade
                    // --------------------------------------------------------
                    $resolvedData = null;
                    if ($comune !== '' && $provincia !== '') {
                        $now     = microtime(true);
                        $elapsed = $now - $lastZornadeCallAt;
                        $minGap  = 0.40;
                        if ($lastZornadeCallAt > 0.0 && $elapsed < $minGap) {
                            usleep((int) (($minGap - $elapsed) * 1_000_000));
                        }
                        $zornade = analyticspro_zornade_lookup_particella(
                            $comune, $provincia, $foglio, $particella
                        );
                        $lastZornadeCallAt = microtime(true);
                        if ($zornade !== null && ($zornade['ok'] ?? false)) {
                            $resolvedData = $zornade;
                        }
                    }

                    // --------------------------------------------------------
                    // Priority 4: WFS-AdE (ultimo fallback)
                    // --------------------------------------------------------
                    if ($resolvedData === null && $codCat !== '') {
                        $now     = microtime(true);
                        $elapsed = $now - $lastWfsCallAt;
                        $minGap  = 0.5;
                        if ($lastWfsCallAt > 0.0 && $elapsed < $minGap) {
                            usleep((int) (($minGap - $elapsed) * 1_000_000));
                        }
                        $wfsData       = analyticspro_wfs_query_service($codCat, $foglio, $particella);
                        $lastWfsCallAt = microtime(true);
                        if ($wfsData !== null && ($wfsData['ok'] ?? false)) {
                            $resolvedData = $wfsData;
                        }
                    }

                    // Persist in cache
                    if ($resolvedData !== null && ($resolvedData['ok'] ?? false)) {
                        if ($db !== null) {
                            $source = $resolvedData['source'] ?? 'WFS-AdE';
                            if ($source === 'Zornade' && $comune !== '' && $provincia !== '') {
                                analyticspro_zornade_save_cached_particella($db, $comune, $provincia, $foglio, $particella, $resolvedData);
                            } elseif ($codCat !== '') {
                                analyticspro_wfs_save_cached_particella($db, $codCat, $foglio, $particella, $resolvedData);
                            }
                        }
                    }
                    if ($db !== null) {
                        $db->close();
                        $db = null;
                    }

                    if ($resolvedData !== null && ($resolvedData['ok'] ?? false)) {
                        $lat      = (float) $resolvedData['lat'];
                        $lng      = (float) $resolvedData['lng'];
                        $verified = 1;
                        $source   = $resolvedData['source'] ?? 'WFS-AdE';
                        $coordSrc = $source === 'Zornade' ? 'zornade' : 'wfs';
                    }
                }
            }

            if ($lat === null || $lng === null) {
                $processed++;
                $updateBatchProgress?->execute(['processed' => $processed, 'id' => $batchId]);
                continue;
            }

            $params = [
                'lat'          => $lat,
                'lng'          => $lng,
                'verified'     => $verified,
                'coord_source' => $coordSrc,
                'provincia'    => $provincia,
                'comune'       => $comune,
                'sezione'      => $sezione,
                'foglio'       => $parcel['foglio'],
                'particella'   => $parcel['particella'],
            ];
            if ($batchId > 0) {
                $params['batch_id'] = $batchId;
            }
            try {
                $updateStmt->execute($params);
            } catch (Throwable $dbEx) {
                // coord_source column might not exist on older installations — retry without it.
                // SQLSTATE 42S22 = unknown column (MySQL/MariaDB).
                $sqlState = $dbEx instanceof \PDOException ? $dbEx->getCode() : '';
                $msgHint  = str_contains($dbEx->getMessage(), 'coord_source');
                if ($sqlState === '42S22' || $msgHint) {
                    $fallbackSql = $batchId > 0
                        ? 'UPDATE properties SET lat = :lat, lng = :lng, posizione_verificata = :verified WHERE import_batch_id = :batch_id AND provincia = :provincia AND comune = :comune AND (sezione <=> :sezione) AND foglio = :foglio AND particella = :particella AND lat IS NULL'
                        : 'UPDATE properties SET lat = :lat, lng = :lng, posizione_verificata = :verified WHERE provincia = :provincia AND comune = :comune AND (sezione <=> :sezione) AND foglio = :foglio AND particella = :particella AND lat IS NULL';
                    unset($params['coord_source']);
                    $pdo->prepare($fallbackSql)->execute($params);
                } else {
                    throw $dbEx;
                }
            }
        } catch (Throwable $exception) {
            $errors++;
            error_log('[enrich_property_coordinates] Error for '
                . $codCat . '/' . $foglio . '/' . $particella . ': '
                . $exception->getMessage());
        }

        $processed++;
        $updateBatchProgress?->execute(['processed' => $processed, 'id' => $batchId]);
    }

    $enrichmentStatus = ($total > 0 && $errors === $total) ? 'failed' : 'completed';

    } catch (Throwable $outerEx) {
        $enrichmentStatus = 'failed';
        $errorMessage     = $outerEx->getMessage();
        error_log('[enrich_batch_coordinates] Errore fatale batch #' . $batchId . ': ' . $outerEx->getMessage());
    } finally {
        if ($batchId > 0) {
            try {
                $pdo->prepare(
                    "UPDATE import_batches SET enrichment_status = :status, enrichment_processed = :processed WHERE id = :id"
                )->execute(['status' => $enrichmentStatus, 'processed' => $processed, 'id' => $batchId]);
            } catch (Throwable) {
                // Se il DB è irraggiungibile non possiamo aggiornare lo stato.
            }
        }
    }
}

// ---------------------------------------------------------------------------
// Chunked enrichment (fallback sincrono quando il worker in background
// non è disponibile, es. hosting con proc_open/shell_exec disabilitati)
// ---------------------------------------------------------------------------

/**
 * Elabora al più $limit particelle non ancora geolocalizzate per il batch.
 *
 * Ogni chiamata:
 *  1. Transisce atomicamente lo stato da 'pending' a 'processing' (se non già fatto).
 *  2. Carica un chunk di particelle con lat IS NULL.
 *  3. Risolve le coordinate e aggiorna properties.
 *  4. Se non rimangono più particelle da risolvere, chiude con 'completed'.
 *
 * @return array{processed:int,total:int,done:bool,status:string}
 */
function analyticspro_enrich_batch_coordinates_chunk(int $batchId, int $limit = 25): array
{
    require_once __DIR__ . '/wfs_lookup.php';
    require_once __DIR__ . '/zornade_lookup.php';
    require_once __DIR__ . '/gml_catalog.php';

    $pdo = analyticspro_db();

    $globalMode = ($batchId === 0);

    if (!$globalMode) {
        // Transizione atomica pending → processing + aggiornamento total
        $initStmt = $pdo->prepare(
            "UPDATE import_batches
             SET enrichment_status = 'processing',
                 enrichment_total  = (
                     SELECT COUNT(*) FROM (
                         SELECT 1 FROM properties
                         WHERE import_batch_id = :bid2 AND lat IS NULL
                         GROUP BY cod_catastale, foglio, particella
                     ) _t
                 )
             WHERE id = :bid AND enrichment_status = 'pending'"
        );
        $initStmt->execute(['bid' => $batchId, 'bid2' => $batchId]);
    }

    // Legge il chunk di particelle non ancora risolte
    if ($globalMode) {
        $selectStmt = $pdo->prepare(
            'SELECT provincia, comune, cod_catastale, sezione, foglio, particella
             FROM properties WHERE lat IS NULL
             GROUP BY provincia, comune, cod_catastale, sezione, foglio, particella
             LIMIT :lim'
        );
        $selectStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    } else {
        $selectStmt = $pdo->prepare(
            'SELECT provincia, comune, cod_catastale, sezione, foglio, particella
             FROM properties
             WHERE import_batch_id = :batch_id AND lat IS NULL
             GROUP BY provincia, comune, cod_catastale, sezione, foglio, particella
             LIMIT :lim'
        );
        $selectStmt->bindValue(':batch_id', $batchId, PDO::PARAM_INT);
        $selectStmt->bindValue(':lim',      $limit,   PDO::PARAM_INT);
    }
    $selectStmt->execute();
    $parcels = $selectStmt->fetchAll();

    if (empty($parcels)) {
        if ($globalMode) {
            return ['processed' => 0, 'total' => 0, 'done' => true, 'status' => 'completed'];
        }
        // Nessuna particella rimasta → enrichment completato
        $pdo->prepare(
            "UPDATE import_batches SET enrichment_status = 'completed' WHERE id = :id AND enrichment_status != 'failed'"
        )->execute(['id' => $batchId]);
        $row = analyticspro_enrich_fetch_batch_state($pdo, $batchId);
        return ['processed' => $row['processed'], 'total' => $row['total'], 'done' => true, 'status' => 'completed'];
    }

    // UPDATE statement varies by mode
    if ($globalMode) {
        $updateStmt = $pdo->prepare(
            'UPDATE properties
             SET lat = :lat, lng = :lng, posizione_verificata = :verified, coord_source = :coord_source
             WHERE provincia = :provincia AND comune = :comune
               AND (sezione <=> :sezione)
               AND foglio = :foglio AND particella = :particella
               AND lat IS NULL'
        );
    } else {
        $updateStmt = $pdo->prepare(
        'UPDATE properties
         SET lat = :lat, lng = :lng, posizione_verificata = :verified, coord_source = :coord_source
         WHERE import_batch_id = :batch_id
           AND provincia = :provincia AND comune = :comune
           AND (sezione <=> :sezione)
           AND foglio = :foglio AND particella = :particella
           AND lat IS NULL'
    );
    }

    $lastWfsCallAt     = 0.0;
    $lastZornadeCallAt = 0.0;
    $chunkProcessed    = 0;

    foreach ($parcels as $parcel) {
        $provincia  = (string) ($parcel['provincia']     ?? '');
        $comune     = (string) ($parcel['comune']        ?? '');
        $codCat     = (string) ($parcel['cod_catastale'] ?? '');
        $sezione    = $parcel['sezione'] !== null ? (string) $parcel['sezione'] : null;
        $foglio     = analyticspro_wfs_normalize_token((string) ($parcel['foglio']     ?? ''));
        $particella = analyticspro_wfs_normalize_token((string) ($parcel['particella'] ?? ''));

        if ($codCat === '' && $comune !== '' && $provincia !== '') {
            $codCat = analyticspro_wfs_lookup_cod_catastale($comune, $provincia) ?? '';
        }

        if ($codCat === '' || $foglio === '' || $particella === '') {
            $chunkProcessed++;
            continue;
        }

        try {
            $lat      = null;
            $lng      = null;
            $verified = 0;
            $coordSrc = null;

            // Priority 1: GML locale
            if ($codCat !== '') {
                $gmlResult = analyticspro_gml_lookup($codCat, $foglio, $particella);
                if ($gmlResult !== null) {
                    $lat      = $gmlResult['lat'];
                    $lng      = $gmlResult['lon'];
                    $verified = 1;
                    $coordSrc = 'gml_locale';
                }
            }

            // Priority 2: Cache SQLite
            if ($coordSrc === null) {
                $db     = null;
                $cached = null;
                try {
                    $db     = analyticspro_wfs_open_cache_db();
                    $cached = analyticspro_wfs_get_cached_particella($db, $codCat, $foglio, $particella);
                    if ($cached !== null) { $db->close(); $db = null; }
                } catch (Throwable) { $db = null; }

                if ($cached !== null && ($cached['ok'] ?? false)) {
                    $lat      = (float) $cached['lat'];
                    $lng      = (float) $cached['lng'];
                    $verified = 1;
                    $coordSrc = 'cache';
                } else {
                    $resolvedData = null;

                    // Priority 3: Zornade
                    if ($comune !== '' && $provincia !== '') {
                        $now = microtime(true);
                        if ($lastZornadeCallAt > 0.0 && ($now - $lastZornadeCallAt) < 0.40) {
                            usleep((int) ((0.40 - ($now - $lastZornadeCallAt)) * 1_000_000));
                        }
                        $zornade = analyticspro_zornade_lookup_particella($comune, $provincia, $foglio, $particella);
                        $lastZornadeCallAt = microtime(true);
                        if ($zornade !== null && ($zornade['ok'] ?? false)) {
                            $resolvedData = $zornade;
                        }
                    }

                    // Priority 4: WFS-AdE
                    if ($resolvedData === null && $codCat !== '') {
                        $now = microtime(true);
                        if ($lastWfsCallAt > 0.0 && ($now - $lastWfsCallAt) < 0.5) {
                            usleep((int) ((0.5 - ($now - $lastWfsCallAt)) * 1_000_000));
                        }
                        $wfsData       = analyticspro_wfs_query_service($codCat, $foglio, $particella);
                        $lastWfsCallAt = microtime(true);
                        if ($wfsData !== null && ($wfsData['ok'] ?? false)) {
                            $resolvedData = $wfsData;
                        }
                    }

                    if ($resolvedData !== null && ($resolvedData['ok'] ?? false) && $db !== null) {
                        $source = $resolvedData['source'] ?? 'WFS-AdE';
                        if ($source === 'Zornade' && $comune !== '' && $provincia !== '') {
                            analyticspro_zornade_save_cached_particella($db, $comune, $provincia, $foglio, $particella, $resolvedData);
                        } elseif ($codCat !== '') {
                            analyticspro_wfs_save_cached_particella($db, $codCat, $foglio, $particella, $resolvedData);
                        }
                    }
                    if ($db !== null) { $db->close(); }

                    if ($resolvedData !== null && ($resolvedData['ok'] ?? false)) {
                        $lat      = (float) $resolvedData['lat'];
                        $lng      = (float) $resolvedData['lng'];
                        $verified = 1;
                        $source   = $resolvedData['source'] ?? 'WFS-AdE';
                        $coordSrc = $source === 'Zornade' ? 'zornade' : 'wfs';
                    }
                }
            }

            if ($lat !== null && $lng !== null) {
                $params = [
                    'lat'          => $lat,
                    'lng'          => $lng,
                    'verified'     => $verified,
                    'coord_source' => $coordSrc,
                    'provincia'    => $provincia,
                    'comune'       => $comune,
                    'sezione'      => $sezione,
                    'foglio'       => $parcel['foglio'],
                    'particella'   => $parcel['particella'],
                ];
                if (!$globalMode) {
                    $params['batch_id'] = $batchId;
                }
                try {
                    $updateStmt->execute($params);
                } catch (Throwable $dbEx) {
                    $sqlState = $dbEx instanceof \PDOException ? $dbEx->getCode() : '';
                    if ($sqlState === '42S22' || str_contains($dbEx->getMessage(), 'coord_source')) {
                        $fallbackParams = array_diff_key($params, ['coord_source' => true]);
                        if ($globalMode) {
                            $pdo->prepare(
                                'UPDATE properties SET lat = :lat, lng = :lng, posizione_verificata = :verified
                                 WHERE provincia = :provincia AND comune = :comune
                                   AND (sezione <=> :sezione) AND foglio = :foglio AND particella = :particella
                                   AND lat IS NULL'
                            )->execute($fallbackParams);
                        } else {
                            $pdo->prepare(
                                'UPDATE properties SET lat = :lat, lng = :lng, posizione_verificata = :verified
                                 WHERE import_batch_id = :batch_id AND provincia = :provincia AND comune = :comune
                                   AND (sezione <=> :sezione) AND foglio = :foglio AND particella = :particella
                                   AND lat IS NULL'
                            )->execute($fallbackParams);
                        }
                    } else {
                        throw $dbEx;
                    }
                }
            }
        } catch (Throwable $exception) {
            error_log('[enrich_chunk] Errore per ' . $codCat . '/' . $foglio . '/' . $particella . ': ' . $exception->getMessage());
        }

        $chunkProcessed++;
    }

    if ($globalMode) {
        // Modalità globale: nessun batch da aggiornare; controlla solo se rimangono righe
        $remaining = (int) $pdo->query('SELECT COUNT(*) FROM properties WHERE lat IS NULL')->fetchColumn();
        $done = ($remaining === 0);
        return ['processed' => $chunkProcessed, 'total' => $remaining + $chunkProcessed, 'done' => $done, 'status' => $done ? 'completed' : 'processing'];
    }

    // Aggiorna il contatore processed incrementalmente
    $pdo->prepare(
        'UPDATE import_batches SET enrichment_processed = enrichment_processed + :delta WHERE id = :id'
    )->execute(['delta' => $chunkProcessed, 'id' => $batchId]);

    // Controlla se rimangono particelle
    $remaining = (int) $pdo->query(
        'SELECT COUNT(*) FROM properties WHERE import_batch_id = ' . (int) $batchId . ' AND lat IS NULL'
    )->fetchColumn();
    $done = ($remaining === 0);

    if ($done) {
        $pdo->prepare(
            "UPDATE import_batches SET enrichment_status = 'completed' WHERE id = :id AND enrichment_status != 'failed'"
        )->execute(['id' => $batchId]);
    }

    $row = analyticspro_enrich_fetch_batch_state($pdo, $batchId);
    return ['processed' => $row['processed'], 'total' => $row['total'], 'done' => $done, 'status' => $done ? 'completed' : 'processing'];
}

/**
 * Legge lo stato di avanzamento enrichment dal DB.
 *
 * @return array{processed:int,total:int}
 */
function analyticspro_enrich_fetch_batch_state(\PDO $pdo, int $batchId): array
{
    $stmt = $pdo->prepare('SELECT enrichment_processed, enrichment_total FROM import_batches WHERE id = :id');
    $stmt->execute(['id' => $batchId]);
    $row = $stmt->fetch() ?: [];
    return [
        'processed' => (int) ($row['enrichment_processed'] ?? 0),
        'total'     => (int) ($row['enrichment_total']     ?? 0),
    ];
}

