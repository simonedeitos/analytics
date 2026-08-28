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

/**
 * Cerca nella riga una colonna il cui nome corrisponde (normalizzato) a uno degli alias.
 * La normalizzazione rimuove accenti, apostrofi, underscore/trattini, converte in
 * maiuscolo e collassa gli spazi — stessa logica di analyticspro_gml_norm_nome_comune().
 *
 * @param array<string,mixed> $row     La riga dell'import (chiavi = nomi colonna originali)
 * @param string[]            $aliases Lista di alias da cercare nell'ordine dato
 * @return array{0:string,1:string}    [valore trovato (stringa), nome colonna trovata] oppure ['','']
 */
function analyticspro_row_pick(array $row, array $aliases): array
{
    // Funzione di normalizzazione locale (non richiede gml_catalog.php)
    $norm = static function (string $s): string {
        $s = strtoupper(trim($s));
        $s = strtr($s, [
            'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ä' => 'A',
            'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
            'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
            'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Ö' => 'O',
            'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
        ]);
        $s = str_replace(["'", "\xe2\x80\x99", '`', '_', '-', '.'], ' ', $s);
        $s = preg_replace('/[^A-Z0-9 ]+/u', ' ', $s) ?? $s;
        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    };

    // Costruisce la mappa normalizzata delle colonne presenti nella riga
    $normalizedKeys = [];
    foreach (array_keys($row) as $colName) {
        $normalizedKeys[$norm((string) $colName)] = (string) $colName;
    }

    // Cerca ogni alias nella mappa
    foreach ($aliases as $alias) {
        $aliasNorm = $norm($alias);
        if (isset($normalizedKeys[$aliasNorm])) {
            $originalCol = $normalizedKeys[$aliasNorm];
            return [trim((string) ($row[$originalCol] ?? '')), $originalCol];
        }
    }

    return ['', ''];
}

function analyticspro_extract_row_payload(array $row): array
{
    // ---- Estrazione tollerante con alias -----------------------------------
    [$comune]        = analyticspro_row_pick($row, ['Comune', 'COMUNE', 'Comune Immobile', 'Citta', 'Città', 'Municipio']);
    [$provinciaRaw]  = analyticspro_row_pick($row, ['Provincia', 'PROVINCIA', 'Prov', 'Sigla', 'Pr']);
    [$foglio]        = analyticspro_row_pick($row, ['Foglio', 'FOGLIO', 'Fg', 'Foglio Catastale']);
    [$particella]    = analyticspro_row_pick($row, ['Particella', 'PARTICELLA', 'Part', 'Mappale', 'Numero']);
    [$subalterno]    = analyticspro_row_pick($row, ['Subalterno', 'SUBALTERNO', 'Sub']);
    [$codCatRaw]     = analyticspro_row_pick($row, ['Codice Catastale', 'CODICE CATASTALE', 'Belfiore', 'Cod Catastale']);

    $provincia = strtoupper(trim($provinciaRaw));

    $resolvedCod = analyticspro_resolve_cod_catastale($codCatRaw, $comune, $provincia);

    // ---- Campi non catastali (nomi colonna già stabili) --------------------
    $contacts  = analyticspro_parse_contacts((string) ($row['Contatti'] ?? $row['contatti'] ?? ''));
    $surname   = trim((string) ($row['Cognome'] ?? $row['Nome'] ?? ''));
    $givenName = trim((string) ($row['Nome1'] ?? $row['Nome Proprietario'] ?? $row['NomeProprietario'] ?? ''));
    $cf        = trim((string) ($row['Codice Fiscale'] ?? $row['Codice fiscale'] ?? ''));

    return [
        'property' => [
            'provincia'     => $provincia,
            'comune'        => $comune,
            'cod_catastale' => trim((string) ($resolvedCod['cod'] ?? '')),
            'sezione'       => trim((string) ($row['Sezione'] ?? '')),
            'foglio'        => $foglio,
            'particella'    => $particella,
            'subalterno'    => $subalterno,
            'indirizzo'     => trim((string) ($row['Indirizzo'] ?? '')),
            'civico'        => trim((string) ($row['Civico'] ?? '')),
            'categoria'     => trim((string) ($row['Categoria'] ?? '')),
            'classe'        => trim((string) ($row['Classe'] ?? '')),
            'consistenza'   => trim((string) ($row['Consistenza'] ?? '')),
            'superficie'    => trim((string) ($row['Superficie'] ?? '')),
            'rendita'       => trim((string) ($row['Rendita'] ?? '')),
            'titolarita'    => trim((string) ($row['Titolarita'] ?? $row['Titolarità'] ?? '')),
            'quota'         => trim((string) ($row['Quota'] ?? '')),
        ],
        'owner' => [
            'tipo'          => preg_match('/^\d{11}$/', $cf) ? 'azienda' : 'persona',
            'nome'          => $givenName,
            'cognome'       => $surname,
            'codice_fiscale' => $cf,
            'telefono'      => $contacts['phones'][0] ?? '',
            'indirizzo'     => trim((string) ($row['Indirizzo Proprietario'] ?? $row['Indirizzo'] ?? '')),
            'email'         => $contacts['emails'][0] ?? '',
            'data_nascita'  => analyticspro_parse_birth_date((string) ($row['Data Nascita'] ?? '')),
            'genere'        => analyticspro_guess_gender($cf),
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

function analyticspro_is_valid_cod_catastale(string $codCatastale): bool
{
    return preg_match('/^[A-Z][0-9]{3}$/', strtoupper(trim($codCatastale))) === 1;
}

/**
 * @return array<int,array{cod:string,provincia:string,nome:string}>
 */
function analyticspro_fetch_cadastral_comuni_rows(): array
{
    static $rows = null;
    if (is_array($rows)) {
        return $rows;
    }

    $rows = [];
    try {
        $stmt = analyticspro_db()->query('SELECT cod_catastale, provincia_sigla, nome_comune FROM cadastral_comuni');
        if ($stmt !== false) {
            foreach ($stmt->fetchAll() as $row) {
                $cod = strtoupper(trim((string) ($row['cod_catastale'] ?? '')));
                $prov = analyticspro_gml_norm_provincia((string) ($row['provincia_sigla'] ?? ''));
                $nome = trim((string) ($row['nome_comune'] ?? ''));
                if ($cod === '' || $nome === '') {
                    continue;
                }
                $rows[] = ['cod' => $cod, 'provincia' => $prov, 'nome' => $nome];
            }
        }
    } catch (Throwable) {
    }

    return $rows;
}

/**
 * @return array{cod:string|null,source:string,note:string}
 */
function analyticspro_resolve_cod_catastale(string $codCatastale, string $comune, string $provincia): array
{
    require_once __DIR__ . '/wfs_lookup.php';
    require_once __DIR__ . '/gml_catalog.php';

    $explicit   = strtoupper(trim($codCatastale));
    $comune     = trim($comune);
    $provincia  = trim($provincia);
    $provNorm   = analyticspro_gml_norm_provincia($provincia);
    $comuneNorm = analyticspro_gml_norm_nome_comune($comune);

    if (analyticspro_is_valid_cod_catastale($explicit)) {
        return ['cod' => $explicit, 'source' => 'esplicito', 'note' => 'Codice catastale già presente nella riga importata'];
    }

    if ($comuneNorm !== '') {
        $gmlCode = analyticspro_gml_belfiore_da_comune($comune, $provincia);
        if ($gmlCode !== null) {
            return ['cod' => $gmlCode, 'source' => 'gml_catalogo', 'note' => 'Codice risolto dal catalogo GML locale'];
        }

        if ($provNorm !== '') {
            $wfsCode = analyticspro_wfs_lookup_cod_catastale($comune, $provincia);
            if ($wfsCode !== null && analyticspro_is_valid_cod_catastale($wfsCode)) {
                return ['cod' => $wfsCode, 'source' => 'comuni_catastali_json', 'note' => 'Codice risolto da comuni_catastali.json'];
            }
        }

        $matches = [];
        foreach (analyticspro_fetch_cadastral_comuni_rows() as $row) {
            if (analyticspro_gml_norm_nome_comune($row['nome']) !== $comuneNorm) {
                continue;
            }
            if ($provNorm !== '' && $row['provincia'] !== '' && $row['provincia'] !== $provNorm) {
                continue;
            }
            $matches[] = $row['cod'];
        }
        $matches = array_values(array_unique($matches));
        if (count($matches) === 1) {
            return ['cod' => $matches[0], 'source' => 'db_cadastral', 'note' => 'Codice risolto dalla tabella cadastral_comuni'];
        }
        if (count($matches) > 1) {
            return [
                'cod' => null,
                'source' => 'non_risolto',
                'note' => 'Omonimia in cadastral_comuni: ' . implode(', ', $matches),
            ];
        }
    }

    return [
        'cod' => null,
        'source' => 'non_risolto',
        'note' => $comune === ''
            ? 'Comune assente: impossibile risolvere il codice catastale'
            : 'Comune/provincia non risolti per "' . $comune . '"' . ($provincia !== '' ? ' (' . $provincia . ')' : ''),
    ];
}

/**
 * @return array{coord_source:array<string,int>,failure_codes:array<string,int>,unresolved_rows:array<int,string>,truncated:bool}
 */
function analyticspro_enrichment_report_default(): array
{
    return [
        'coord_source' => [],
        'failure_codes' => [],
        'unresolved_rows' => [],
        'truncated' => false,
    ];
}

/**
 * @return array{coord_source:array<string,int>,failure_codes:array<string,int>,unresolved_rows:array<int,string>,truncated:bool}
 */
function analyticspro_enrichment_report_load(PDO $pdo, int $batchId): array
{
    $stmt = $pdo->prepare('SELECT enrichment_report FROM import_batches WHERE id = :id');
    $stmt->execute(['id' => $batchId]);
    $raw = $stmt->fetchColumn();
    if (!is_string($raw) || trim($raw) === '') {
        return analyticspro_enrichment_report_default();
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        return analyticspro_enrichment_report_default();
    }
    return array_merge(analyticspro_enrichment_report_default(), $decoded);
}

function analyticspro_enrichment_report_save(PDO $pdo, int $batchId, array $report): void
{
    $pdo->prepare('UPDATE import_batches SET enrichment_report = :report WHERE id = :id')
        ->execute([
            'report' => json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'id' => $batchId,
        ]);
}

function analyticspro_enrichment_report_add_success(array &$report, string $coordSource): void
{
    if ($coordSource === '') {
        return;
    }
    $report['coord_source'][$coordSource] = (int) ($report['coord_source'][$coordSource] ?? 0) + 1;
}

function analyticspro_enrichment_report_add_failure(array &$report, array $parcel, string $code, string $note): void
{
    $report['failure_codes'][$code] = (int) ($report['failure_codes'][$code] ?? 0) + 1;
    if (count($report['unresolved_rows']) >= 100) {
        $report['truncated'] = true;
        return;
    }
    $comune = trim((string) ($parcel['comune'] ?? ''));
    $foglio = trim((string) ($parcel['foglio'] ?? ''));
    $part   = trim((string) ($parcel['particella'] ?? ''));
    $label  = trim(($comune !== '' ? $comune : 'Comune sconosciuto')
        . ' F.' . ($foglio !== '' ? $foglio : '?')
        . ' P.' . ($part !== '' ? $part : '?'));
    $report['unresolved_rows'][] = $label . ' — ' . $code . ($note !== '' ? ': ' . $note : '');
}

/**
 * @return array{lat:?float,lng:?float,verified:int,coord_source:?string,failure_code:?string,failure_note:string,cod_catastale:?string,resolution_source:string}
 */
function analyticspro_enrich_resolve_single_parcel(array $parcel, float &$lastWfsCallAt, float &$lastZornadeCallAt): array
{
    require_once __DIR__ . '/wfs_lookup.php';
    require_once __DIR__ . '/zornade_lookup.php';
    require_once __DIR__ . '/gml_catalog.php';

    $provincia      = (string) ($parcel['provincia'] ?? '');
    $comune         = (string) ($parcel['comune'] ?? '');
    $codCatInput    = (string) ($parcel['cod_catastale'] ?? '');
    $sezione        = $parcel['sezione'] !== null ? (string) $parcel['sezione'] : null;
    $foglioInput    = trim((string) ($parcel['foglio'] ?? ''));
    $particellaInput = trim((string) ($parcel['particella'] ?? ''));

    if ($foglioInput === '' || $particellaInput === '') {
        return [
            'lat' => null,
            'lng' => null,
            'verified' => 0,
            'coord_source' => null,
            'failure_code' => 'dati_incompleti',
            'failure_note' => 'Foglio o particella mancanti nella riga importata',
            'cod_catastale' => null,
            'resolution_source' => 'non_risolto',
        ];
    }

    $resolution = analyticspro_resolve_cod_catastale($codCatInput, $comune, $provincia);
    $codCat     = $resolution['cod'];
    if ($codCat === null) {
        return [
            'lat' => null,
            'lng' => null,
            'verified' => 0,
            'coord_source' => null,
            'failure_code' => 'comune_non_risolto',
            'failure_note' => $resolution['note'],
            'cod_catastale' => null,
            'resolution_source' => $resolution['source'],
        ];
    }

    $gmlResult = analyticspro_gml_lookup($codCat, $foglioInput, $particellaInput);
    if ($gmlResult !== null) {
        return [
            'lat' => (float) $gmlResult['lat'],
            'lng' => (float) $gmlResult['lon'],
            'verified' => 1,
            'coord_source' => 'gml_locale',
            'failure_code' => null,
            'failure_note' => '',
            'cod_catastale' => $codCat,
            'resolution_source' => $resolution['source'],
        ];
    }

    $gmlDiag = analyticspro_gml_diagnose_lookup($codCat, $foglioInput, $particellaInput);
    $foglioRemote = analyticspro_wfs_normalize_token($foglioInput);
    $particellaRemote = analyticspro_wfs_normalize_token($particellaInput);

    $cached = null;
    $db = null;
    if ($foglioRemote !== '' && $particellaRemote !== '') {
        try {
            $db     = analyticspro_wfs_open_cache_db();
            $cached = analyticspro_wfs_get_cached_particella($db, $codCat, $foglioRemote, $particellaRemote);
            if ($cached !== null) {
                $db->close();
                $db = null;
            }
        } catch (Throwable) {
            $db = null;
        }
    }

    if ($cached !== null && ($cached['ok'] ?? false)) {
        return [
            'lat' => (float) $cached['lat'],
            'lng' => (float) $cached['lng'],
            'verified' => 1,
            'coord_source' => 'cache',
            'failure_code' => null,
            'failure_note' => '',
            'cod_catastale' => $codCat,
            'resolution_source' => $resolution['source'],
        ];
    }

    $resolvedData = null;
    if ($foglioRemote !== '' && $particellaRemote !== '' && $comune !== '' && $provincia !== '') {
        $now     = microtime(true);
        $elapsed = $now - $lastZornadeCallAt;
        $minGap  = 0.40;
        if ($lastZornadeCallAt > 0.0 && $elapsed < $minGap) {
            usleep((int) (($minGap - $elapsed) * 1_000_000));
        }
        $zornade = analyticspro_zornade_lookup_particella($comune, $provincia, $foglioRemote, $particellaRemote, $sezione);
        $lastZornadeCallAt = microtime(true);
        if ($zornade !== null && ($zornade['ok'] ?? false)) {
            $resolvedData = $zornade;
        }
    }

    if ($resolvedData === null && $foglioRemote !== '' && $particellaRemote !== '') {
        $now     = microtime(true);
        $elapsed = $now - $lastWfsCallAt;
        $minGap  = 0.5;
        if ($lastWfsCallAt > 0.0 && $elapsed < $minGap) {
            usleep((int) (($minGap - $elapsed) * 1_000_000));
        }
        $wfsData       = analyticspro_wfs_query_service($codCat, $foglioRemote, $particellaRemote);
        $lastWfsCallAt = microtime(true);
        if ($wfsData !== null && ($wfsData['ok'] ?? false)) {
            $resolvedData = $wfsData;
        }
    }

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
                analyticspro_zornade_save_cached_particella($db, $comune, $provincia, $foglioRemote, $particellaRemote, $resolvedData);
            } else {
                analyticspro_wfs_save_cached_particella($db, $codCat, $foglioRemote, $particellaRemote, $resolvedData);
            }
            $db->close();
        }

        $source = $resolvedData['source'] ?? 'WFS-AdE';
        return [
            'lat' => (float) $resolvedData['lat'],
            'lng' => (float) $resolvedData['lng'],
            'verified' => 1,
            'coord_source' => $source === 'Zornade' ? 'zornade' : 'wfs',
            'failure_code' => null,
            'failure_note' => '',
            'cod_catastale' => $codCat,
            'resolution_source' => $resolution['source'],
        ];
    }

    if ($db !== null) {
        $db->close();
    }

    $failureCode = $gmlDiag['code'] ?? null;
    $failureNote = $gmlDiag['note'] ?? '';
    if ($failureCode === 'gml_mancante') {
        $failureCode = 'provider_remoto_fallito';
        $failureNote = $failureNote . '. Anche cache/Zornade/WFS non hanno restituito coordinate';
    }

    return [
        'lat' => null,
        'lng' => null,
        'verified' => 0,
        'coord_source' => null,
        'failure_code' => $failureCode ?? 'provider_remoto_fallito',
        'failure_note' => $failureNote !== '' ? $failureNote : 'Lookup remoto non riuscito',
        'cod_catastale' => $codCat,
        'resolution_source' => $resolution['source'],
    ];
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

    try {
        $resolved = analyticspro_enrich_resolve_single_parcel($property, $lastWfsCallAt, $lastZornadeCallAt);
        if ($resolved['lat'] === null || $resolved['lng'] === null) {
            return ['lat' => null, 'lng' => null, 'verified' => 0];
        }
        return [
            'lat' => (float) $resolved['lat'],
            'lng' => (float) $resolved['lng'],
            'verified' => (int) $resolved['verified'],
        ];
    } catch (Throwable $exception) {
        $codCatastale = (string) ($property['cod_catastale'] ?? '');
        $foglio = trim((string) ($property['foglio'] ?? ''));
        $particella = trim((string) ($property['particella'] ?? ''));
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
    $report           = analyticspro_enrichment_report_default();

    try {
        if ($batchId > 0) {
            $pdo->prepare("UPDATE import_batches SET enrichment_status = 'processing', enrichment_report = :report WHERE id = :id")
                ->execute([
                    'report' => json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'id' => $batchId,
                ]);
        }

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

        $lastWfsCallAt     = 0.0;
        $lastZornadeCallAt = 0.0;
        $processed         = 0;
        $errors            = 0;

        foreach ($uniqueParcels as $parcel) {
            $provincia = (string) ($parcel['provincia'] ?? '');
            $comune    = (string) ($parcel['comune'] ?? '');
            $sezione   = $parcel['sezione'] !== null ? (string) $parcel['sezione'] : null;

            try {
                $resolved = analyticspro_enrich_resolve_single_parcel($parcel, $lastWfsCallAt, $lastZornadeCallAt);
                if ($resolved['lat'] === null || $resolved['lng'] === null) {
                    $errors++;
                    if ($batchId > 0) {
                        analyticspro_enrichment_report_add_failure(
                            $report,
                            $parcel,
                            (string) ($resolved['failure_code'] ?? 'provider_remoto_fallito'),
                            (string) ($resolved['failure_note'] ?? '')
                        );
                    }
                    $processed++;
                    $updateBatchProgress?->execute(['processed' => $processed, 'id' => $batchId]);
                    continue;
                }

                $params = [
                    'lat' => $resolved['lat'],
                    'lng' => $resolved['lng'],
                    'verified' => $resolved['verified'],
                    'coord_source' => $resolved['coord_source'],
                    'provincia' => $provincia,
                    'comune' => $comune,
                    'sezione' => $sezione,
                    'foglio' => $parcel['foglio'],
                    'particella' => $parcel['particella'],
                ];
                if ($batchId > 0) {
                    $params['batch_id'] = $batchId;
                }
                try {
                    $updateStmt->execute($params);
                } catch (Throwable $dbEx) {
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
                if ($batchId > 0) {
                    analyticspro_enrichment_report_add_success($report, (string) ($resolved['coord_source'] ?? ''));
                }
            } catch (Throwable $exception) {
                $errors++;
                if ($batchId > 0) {
                    analyticspro_enrichment_report_add_failure($report, $parcel, 'provider_remoto_fallito', $exception->getMessage());
                }
                error_log('[enrich_property_coordinates] Error for '
                    . $comune . '/' . ($parcel['foglio'] ?? '') . '/' . ($parcel['particella'] ?? '') . ': '
                    . $exception->getMessage());
            }

            $processed++;
            $updateBatchProgress?->execute(['processed' => $processed, 'id' => $batchId]);
        }

        $enrichmentStatus = ($total > 0 && $errors === $total) ? 'failed' : 'completed';
    } catch (Throwable $outerEx) {
        $enrichmentStatus = 'failed';
        error_log('[enrich_batch_coordinates] Errore fatale batch #' . $batchId . ': ' . $outerEx->getMessage());
    } finally {
        if ($batchId > 0) {
            try {
                $pdo->prepare(
                    "UPDATE import_batches SET enrichment_status = :status, enrichment_processed = :processed, enrichment_report = :report WHERE id = :id"
                )->execute([
                    'status' => $enrichmentStatus,
                    'processed' => $processed,
                    'report' => json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'id' => $batchId,
                ]);
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
                 ),
                 enrichment_report = :report
             WHERE id = :bid AND enrichment_status = 'pending'"
        );
        $initStmt->execute([
            'bid' => $batchId,
            'bid2' => $batchId,
            'report' => json_encode(analyticspro_enrichment_report_default(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
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
        return [
            'processed' => $row['processed'],
            'total' => $row['total'],
            'done' => true,
            'status' => 'completed',
            'enrichment_report' => $row['report'],
        ];
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
    $report            = !$globalMode ? analyticspro_enrichment_report_load($pdo, $batchId) : analyticspro_enrichment_report_default();

    foreach ($parcels as $parcel) {
        $provincia = (string) ($parcel['provincia'] ?? '');
        $comune    = (string) ($parcel['comune'] ?? '');
        $sezione   = $parcel['sezione'] !== null ? (string) $parcel['sezione'] : null;

        try {
            $resolved = analyticspro_enrich_resolve_single_parcel($parcel, $lastWfsCallAt, $lastZornadeCallAt);
            if ($resolved['lat'] !== null && $resolved['lng'] !== null) {
                $params = [
                    'lat' => $resolved['lat'],
                    'lng' => $resolved['lng'],
                    'verified' => $resolved['verified'],
                    'coord_source' => $resolved['coord_source'],
                    'provincia' => $provincia,
                    'comune' => $comune,
                    'sezione' => $sezione,
                    'foglio' => $parcel['foglio'],
                    'particella' => $parcel['particella'],
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
                if (!$globalMode) {
                    analyticspro_enrichment_report_add_success($report, (string) ($resolved['coord_source'] ?? ''));
                }
            } elseif (!$globalMode) {
                analyticspro_enrichment_report_add_failure(
                    $report,
                    $parcel,
                    (string) ($resolved['failure_code'] ?? 'provider_remoto_fallito'),
                    (string) ($resolved['failure_note'] ?? '')
                );
            }
        } catch (Throwable $exception) {
            if (!$globalMode) {
                analyticspro_enrichment_report_add_failure($report, $parcel, 'provider_remoto_fallito', $exception->getMessage());
            }
            error_log('[enrich_chunk] Errore per ' . $comune . '/' . ($parcel['foglio'] ?? '') . '/' . ($parcel['particella'] ?? '') . ': ' . $exception->getMessage());
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
        'UPDATE import_batches SET enrichment_processed = enrichment_processed + :delta, enrichment_report = :report WHERE id = :id'
    )->execute([
        'delta' => $chunkProcessed,
        'report' => json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'id' => $batchId,
    ]);

    // Controlla se rimangono particelle
    $remainStmt = $pdo->prepare('SELECT COUNT(*) FROM properties WHERE import_batch_id = :bid AND lat IS NULL');
    $remainStmt->execute(['bid' => $batchId]);
    $remaining = (int) $remainStmt->fetchColumn();
    $done = ($remaining === 0);

    if ($done) {
        $pdo->prepare(
            "UPDATE import_batches SET enrichment_status = 'completed' WHERE id = :id AND enrichment_status != 'failed'"
        )->execute(['id' => $batchId]);
    }

    $row = analyticspro_enrich_fetch_batch_state($pdo, $batchId);
    return [
        'processed' => $row['processed'],
        'total' => $row['total'],
        'done' => $done,
        'status' => $done ? 'completed' : 'processing',
        'enrichment_report' => $row['report'],
    ];
}

/**
 * Legge lo stato di avanzamento enrichment dal DB.
 *
 * @return array{processed:int,total:int,report:array{coord_source:array<string,int>,failure_codes:array<string,int>,unresolved_rows:array<int,string>,truncated:bool}}
 */
function analyticspro_enrich_fetch_batch_state(\PDO $pdo, int $batchId): array
{
    $stmt = $pdo->prepare('SELECT enrichment_processed, enrichment_total, enrichment_report FROM import_batches WHERE id = :id');
    $stmt->execute(['id' => $batchId]);
    $row = $stmt->fetch() ?: [];
    $report = analyticspro_enrichment_report_default();
    if (is_string($row['enrichment_report'] ?? null) && trim((string) $row['enrichment_report']) !== '') {
        $decoded = json_decode((string) $row['enrichment_report'], true);
        if (is_array($decoded)) {
            $report = array_merge($report, $decoded);
        }
    }
    return [
        'processed' => (int) ($row['enrichment_processed'] ?? 0),
        'total'     => (int) ($row['enrichment_total']     ?? 0),
        'report'    => $report,
    ];
}
