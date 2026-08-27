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
    // Lookup parcel coordinates from the MySQL cadastral tables.
    // Uses interior_point (pre-computed during ADE import) which represents
    // a point guaranteed to be inside the polygon boundary.
    // Le geometrie ADE sono salvate come WKT in ordine (lat, lng) per usare
    // ST_GeomFromText(wkt, 4326) a 2 parametri (compatibile MySQL/MariaDB):
    // quindi ST_X() restituisce LAT e ST_Y() restituisce LNG.
    $pdo = analyticspro_db();

    try {
        $stmt = $pdo->prepare(
            'SELECT ST_X(p.interior_point) AS lat, ST_Y(p.interior_point) AS lng
             FROM cadastral_parcels p
             JOIN cadastral_comuni c ON c.id = p.comune_id
             WHERE c.cod_catastale = :cod_catastale
               AND p.foglio        = :foglio
               AND p.particella    = :particella
             LIMIT 1'
        );
        $stmt->execute([
            'cod_catastale' => $property['cod_catastale'],
            'foglio'        => $property['foglio'],
            'particella'    => $property['particella'],
        ]);
        $coords = $stmt->fetch();
        if (!$coords || $coords['lat'] === null) {
            return ['lat' => null, 'lng' => null, 'verified' => 0];
        }
        return [
            'lat'      => $coords['lat'],
            'lng'      => $coords['lng'],
            'verified' => 1,
        ];
    } catch (Throwable $exception) {
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
    $insertProperty = $pdo->prepare('INSERT INTO properties (user_id, import_batch_id, provincia, comune, cod_catastale, sezione, foglio, particella, subalterno, indirizzo, civico, categoria, classe, consistenza, superficie, rendita, titolarita, quota, lat, lng, posizione_verificata, stato, stato_personalizzato, colore_marker) VALUES (:user_id, :import_batch_id, :provincia, :comune, :cod_catastale, :sezione, :foglio, :particella, :subalterno, :indirizzo, :civico, :categoria, :classe, :consistenza, :superficie, :rendita, :titolarita, :quota, :lat, :lng, :posizione_verificata, :stato, :stato_personalizzato, :colore_marker)');
    $updateProperty = $pdo->prepare('UPDATE properties SET import_batch_id = :import_batch_id, cod_catastale = :cod_catastale, indirizzo = :indirizzo, civico = :civico, categoria = :categoria, classe = :classe, consistenza = :consistenza, superficie = :superficie, rendita = :rendita, titolarita = :titolarita, quota = :quota, lat = :lat, lng = :lng, posizione_verificata = :posizione_verificata WHERE id = :id');
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

            $coords = analyticspro_lookup_cadastral_coordinates($property);
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
                    'lat' => $coords['lat'],
                    'lng' => $coords['lng'],
                    'posizione_verificata' => $coords['verified'],
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
                    'lat' => $coords['lat'],
                    'lng' => $coords['lng'],
                    'posizione_verificata' => $coords['verified'],
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

        $pdo->prepare("UPDATE import_batches SET status = 'completed', completed_at = NOW(), processed_rows = total_rows WHERE id = :id")->execute(['id' => $batchId]);
    } catch (Throwable $exception) {
        $pdo->prepare("UPDATE import_batches SET status = 'failed', error_message = :message, completed_at = NOW() WHERE id = :id")
            ->execute(['message' => $exception->getMessage(), 'id' => $batchId]);
        throw $exception;
    }
}
