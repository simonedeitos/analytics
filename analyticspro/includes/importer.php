<?php

declare(strict_types=1);

if (!defined('ANALYTICSPRO_ENRICH_MAX_ATTEMPTS')) {
    define('ANALYTICSPRO_ENRICH_MAX_ATTEMPTS', 3);
}

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
    $noEmail = preg_replace('/\s+-\s*/u', ',', $noEmail) ?? $noEmail;
    $noEmail = str_replace([';', "\r", "\n", "\t"], [',', ' ', ' ', ' '], $noEmail);

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

function analyticspro_normalize_quota(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/\s*\/\s*/', '/', $value) ?? $value;
    if (
        preg_match('/^\d{4}[-\/\.]\d{2}[-\/\.]\d{2}$/', $value) === 1
        || preg_match('/^\d{2}[-\/\.]\d{2}[-\/\.]\d{4}$/', $value) === 1
    ) {
        return '';
    }

    return $value;
}

function analyticspro_parse_coordinate(?string $value): ?float
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $normalized = str_replace(',', '.', $value);
    if (!is_numeric($normalized)) {
        return null;
    }

    return (float) $normalized;
}

function analyticspro_is_partita_iva(?string $value): bool
{
    $value = trim((string) $value);
    return $value !== '' && preg_match('/^\d{11}$/', $value) === 1;
}

function analyticspro_guess_gender(?string $cf): ?string
{
    $cf = strtoupper(trim((string) $cf));
    if ($cf === '') {
        return null;
    }
    if (analyticspro_is_partita_iva($cf)) {
        return 'Società';
    }
    if (preg_match('/^[A-Z]{6}\d{2}[A-Z]\d{2}[A-Z]\d{3}[A-Z]$/', $cf) !== 1) {
        return null;
    }

    $day = (int) substr($cf, 9, 2);
    $normalizedDay = $day > 40 ? $day - 40 : $day;
    if ($normalizedDay < 1 || $normalizedDay > 31) {
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

function analyticspro_normalize_import_header(string $header): string
{
    $header = trim($header);
    if ($header === '') {
        return '';
    }
    $header = mb_strtoupper($header, 'UTF-8');
    $header = strtr($header, [
        'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ä' => 'A',
        'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Ö' => 'O',
        'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
    ]);
    $header = str_replace(["'", '’', '`'], '', $header);
    $header = preg_replace('/[^A-Z0-9]+/u', ' ', $header) ?? $header;
    return trim(preg_replace('/\s+/u', ' ', $header) ?? $header);
}

function analyticspro_import_phone_separator(): string
{
    // I telefoni multipli restano nello stesso campo DB, separati da ';'.
    return ';';
}

function analyticspro_extract_row_header_variants(string $header): array
{
    $normalized = analyticspro_normalize_import_header($header);
    if ($normalized === '') {
        return [];
    }

    $variants = [$normalized];
    $deduped = preg_replace('/\s+DUP\s+\d+$/u', '', $normalized) ?? $normalized;
    if ($deduped !== '' && $deduped !== $normalized) {
        $variants[] = $deduped;
    }

    return array_values(array_unique($variants));
}

function analyticspro_extract_row_values(array $row, array $aliases): array
{
    $values = [];
    $seen = [];
    foreach ($aliases as $alias) {
        if (array_key_exists($alias, $row)) {
            $value = trim((string) $row[$alias]);
            if ($value !== '') {
                $key = mb_strtoupper($value, 'UTF-8');
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $values[] = $value;
                }
            }
        }
    }

    $normalizedRow = [];
    foreach ($row as $key => $value) {
        foreach (analyticspro_extract_row_header_variants((string) $key) as $norm) {
            $normalizedRow[$norm] ??= [];
            $normalizedRow[$norm][] = $value;
        }
    }

    foreach ($aliases as $alias) {
        foreach (analyticspro_extract_row_header_variants((string) $alias) as $normAlias) {
            foreach ($normalizedRow[$normAlias] ?? [] as $value) {
                $value = trim((string) $value);
                if ($value === '') {
                    continue;
                }
                $key = mb_strtoupper($value, 'UTF-8');
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $values[] = $value;
                }
            }
        }
    }

    return $values;
}

function analyticspro_extract_row_value(array $row, array $aliases): string
{
    $values = analyticspro_extract_row_values($row, $aliases);
    return $values[0] ?? '';
}

function analyticspro_merge_name_columns(array $row): string
{
    $pieces = [];
    $seen = [];
    foreach (analyticspro_extract_row_values($row, ['Nome', 'Nome1', 'Nome2', 'Nome3', 'Nome Proprietario', 'NomeProprietario']) as $value) {
        $tokens = preg_split('/\s+/u', trim($value)) ?: [];
        foreach ($tokens as $token) {
            $token = trim((string) $token);
            if ($token === '') {
                continue;
            }
            $key = mb_strtoupper($token, 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $pieces[] = $token;
        }
    }

    return implode(' ', $pieces);
}

function analyticspro_properties_has_column(string $column): bool
{
    static $cache = [];
    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }

    try {
        $stmt = analyticspro_db()->prepare('SHOW COLUMNS FROM properties LIKE :column');
        $stmt->execute(['column' => $column]);
        $cache[$column] = (bool) $stmt->fetch();
    } catch (Throwable) {
        $cache[$column] = false;
    }

    return $cache[$column];
}

function analyticspro_properties_has_piano_column(): bool
{
    return analyticspro_properties_has_column('piano');
}

function analyticspro_properties_has_coord_source_column(): bool
{
    return analyticspro_properties_has_column('coord_source');
}

function analyticspro_properties_has_enrichment_attempt_columns(): bool
{
    static $hasColumns = null;
    if ($hasColumns !== null) {
        return $hasColumns;
    }

    $hasColumns = analyticspro_properties_has_column('enrichment_attempts')
        && analyticspro_properties_has_column('enrichment_last_attempt_at')
        && analyticspro_properties_has_column('enrichment_last_error_code')
        && analyticspro_properties_has_column('enrichment_last_error_note');

    return $hasColumns;
}

function analyticspro_properties_has_provincia_originale_column(): bool
{
    return analyticspro_properties_has_column('provincia_originale');
}

/**
 * @return array{sigla:string,originale:string,warning:string}
 */
function analyticspro_import_normalize_provincia(string $provinciaRaw, string $comune, string $codCatastale, ?int $rowNumber = null): array
{
    require_once __DIR__ . '/cadastral_map.php';

    $normalized = analyticspro_normalize_provincia_sigla($provinciaRaw, $codCatastale, $comune);
    $originale = trim($provinciaRaw);
    $sigla = trim((string) ($normalized['sigla'] ?? ''));
    $source = (string) ($normalized['source'] ?? 'unresolved');
    $rowLabel = $rowNumber !== null ? (' (riga ' . ($rowNumber + 1) . ')') : '';
    $quotedOriginal = '"' . ($originale !== '' ? $originale : '(vuota)') . '"';

    if ($sigla !== '') {
        if ($source === 'input' || $originale === '') {
            return ['sigla' => $sigla, 'originale' => $originale, 'warning' => ''];
        }

        $sourceMessage = $source === 'cod_catastale'
            ? 'verrà usata la sigla derivata dal codice catastale (' . $sigla . ')'
            : ($source === 'comune'
                ? 'verrà usata la sigla derivata dal comune (' . $sigla . ')'
                : 'verrà usata la sigla normalizzata (' . $sigla . ')');

        return [
            'sigla' => $sigla,
            'originale' => $originale,
            'warning' => 'Provincia non riconosciuta come sigla valida: ' . $quotedOriginal . $rowLabel . ' — ' . $sourceMessage . '.',
        ];
    }

    return [
        'sigla' => '',
        'originale' => $originale,
        'warning' => 'Provincia non riconosciuta: ' . $quotedOriginal . $rowLabel . ' — il valore resta da correggere.',
    ];
}

function analyticspro_extract_row_payload(array $row, ?int $rowNumber = null): array
{
    $contactValues = analyticspro_extract_row_values($row, ['Contatti', 'Telefono', 'Telefoni', 'Cellulare']);
    $emails = [];
    $phones = [];
    foreach ($contactValues as $contactValue) {
        $parsedContacts = analyticspro_parse_contacts($contactValue);
        $emails = array_merge($emails, $parsedContacts['emails']);
        $phones = array_merge($phones, $parsedContacts['phones']);
    }
    $phones = array_values(array_unique(array_filter(array_map('trim', $phones), static fn ($value) => $value !== '')));
    $emails = array_values(array_unique(array_filter(array_map(static fn ($value) => strtolower(trim((string) $value)), $emails), static fn ($value) => $value !== '')));
    $surname = analyticspro_extract_row_value($row, ['Cognome', 'Cognome Proprietario', 'CognomeProprietario']);
    $givenName = analyticspro_merge_name_columns($row);
    if ($surname === '' && $givenName === '') {
        $surname = analyticspro_extract_row_value($row, ['Nome']);
    }
    $email = strtolower(analyticspro_extract_row_value($row, ['Email', 'E-mail', 'Mail']));
    if ($email === '') {
        $email = $emails[0] ?? '';
    }
    $cf = analyticspro_extract_row_value($row, ['Codice Fiscale']);
    $provinciaRaw = analyticspro_extract_row_value($row, ['Provincia', 'Prov']);
    $comune = analyticspro_extract_row_value($row, ['Comune', 'Comune Catastale', 'Comune Immobile']);
    $codCatastale = analyticspro_extract_row_value($row, ['Codice Catastale', 'Codice Comune', 'Cod Comune', 'Codice Belfiore', 'Belfiore', 'Cod_Catastale']);
    $provinciaNormalized = analyticspro_import_normalize_provincia($provinciaRaw, $comune, $codCatastale, $rowNumber);
    $provincia = $provinciaNormalized['sigla'];
    $resolvedCod = analyticspro_resolve_cod_catastale(
        $codCatastale,
        $comune,
        $provincia
    );

    return [
        'property' => [
            'provincia' => $provincia,
            'provincia_originale' => $provinciaNormalized['originale'],
            'comune' => $comune,
            'cod_catastale' => trim((string) ($resolvedCod['cod'] ?? '')),
            'sezione' => analyticspro_extract_row_value($row, ['Sezione']),
            'foglio' => analyticspro_extract_row_value($row, ['Foglio']),
            'particella' => analyticspro_extract_row_value($row, ['Particella']),
            'subalterno' => analyticspro_extract_row_value($row, ['Subalterno', 'Sub']),
            'indirizzo' => analyticspro_extract_row_value($row, ['Indirizzo']),
            'civico' => analyticspro_extract_row_value($row, ['Civico']),
            'categoria' => analyticspro_extract_row_value($row, ['Categoria']),
            'classe' => analyticspro_extract_row_value($row, ['Classe']),
            'piano' => analyticspro_extract_row_value($row, ['Piano']),
            'consistenza' => analyticspro_extract_row_value($row, ['Consistenza']),
            'superficie' => analyticspro_extract_row_value($row, ['Superficie']),
            'rendita' => analyticspro_extract_row_value($row, ['Rendita']),
            'titolarita' => analyticspro_extract_row_value($row, ['Titolarita', 'Titolarità']),
            'quota' => analyticspro_normalize_quota(analyticspro_extract_row_value($row, ['Quota'])),
            'lat' => analyticspro_parse_coordinate(analyticspro_extract_row_value($row, ['Latitudine', 'Lat', 'Latitude'])),
            'lng' => analyticspro_parse_coordinate(analyticspro_extract_row_value($row, ['Longitudine', 'Lng', 'Lon', 'Longitude'])),
        ],
        'owner' => [
            'tipo' => analyticspro_is_partita_iva($cf) ? 'azienda' : 'persona',
            'nome' => $givenName,
            'cognome' => $surname,
            'codice_fiscale' => $cf,
            'telefono' => implode(analyticspro_import_phone_separator(), $phones),
            'indirizzo' => analyticspro_extract_row_value($row, ['Indirizzo Proprietario', 'Indirizzo']),
            'email' => $email,
            'data_nascita' => analyticspro_parse_birth_date(analyticspro_extract_row_value($row, ['Data Nascita'])),
            'luogo_nascita' => analyticspro_extract_row_value($row, ['Nato A', 'Luogo Nascita', 'Luogo di Nascita', 'Comune Nascita']),
            'genere' => analyticspro_guess_gender($cf),
        ],
        'note' => analyticspro_extract_row_value($row, ['Note', 'note']),
        'warnings' => $provinciaNormalized['warning'] !== '' ? [$provinciaNormalized['warning']] : [],
    ];
}

function analyticspro_find_conflicts(array $rows, int $tenantId): array
{
    $pdo = analyticspro_db();
    $findProperty = $pdo->prepare('SELECT id, comune, foglio, particella, subalterno FROM properties WHERE user_id = :user_id AND provincia = :provincia AND comune = :comune AND sezione <=> :sezione AND foglio = :foglio AND particella = :particella AND subalterno <=> :subalterno LIMIT 1');
    $findOwner = $pdo->prepare('SELECT * FROM property_owners WHERE property_id = :property_id AND is_current = 1 LIMIT 1');
    $conflicts = [];

    foreach ($rows as $index => $row) {
        $payload = analyticspro_extract_row_payload($row, $index);
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
    require_once __DIR__ . '/cadastral_map.php';

    $explicit   = strtoupper(trim($codCatastale));
    $comune     = trim($comune);
    $provincia  = trim($provincia);
    $provinciaNormalized = analyticspro_normalize_provincia_sigla($provincia, $explicit, $comune);
    $provNorm   = analyticspro_gml_norm_provincia((string) ($provinciaNormalized['sigla'] ?? ''));
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
            $wfsCode = analyticspro_wfs_lookup_cod_catastale($comune, $provNorm);
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
 * @return array{coord_source:array<string,int>,attempt_failures:array<string,int>,failure_codes:array<string,int>,unresolved_rows:array<int,string>,truncated:bool,missing_comuni:array<int,array{name:string,provincia:string,belfiore:string}>,missing_comuni_truncated:bool}
 */
function analyticspro_enrichment_report_default(): array
{
    return [
        'coord_source' => [],
        'attempt_failures' => [],
        'failure_codes' => [],
        'unresolved_rows' => [],
        'truncated' => false,
        'missing_comuni' => [],
        'missing_comuni_truncated' => false,
    ];
}

/**
 * @return array{coord_source:array<string,int>,attempt_failures:array<string,int>,failure_codes:array<string,int>,unresolved_rows:array<int,string>,truncated:bool,missing_comuni:array<int,array{name:string,provincia:string,belfiore:string}>,missing_comuni_truncated:bool}
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

function analyticspro_enrichment_report_add_attempt_failure(array &$report, string $code): void
{
    if ($code === '') {
        return;
    }
    $report['attempt_failures'][$code] = (int) ($report['attempt_failures'][$code] ?? 0) + 1;
}

function analyticspro_enrichment_report_add_missing_comune(array &$report, array $parcel, ?string $belfiore = null): void
{
    $name = trim((string) ($parcel['comune'] ?? ''));
    $provincia = trim((string) ($parcel['provincia'] ?? ''));
    $code = strtoupper(trim((string) ($belfiore ?? $parcel['cod_catastale'] ?? '')));
    if (!analyticspro_is_valid_cod_catastale($code)) {
        $code = '';
    }
    $key = mb_strtolower($name, 'UTF-8') . '|' . strtoupper($provincia) . '|' . $code;
    foreach (($report['missing_comuni'] ?? []) as $entry) {
        $entryKey = mb_strtolower((string) ($entry['name'] ?? ''), 'UTF-8')
            . '|' . strtoupper((string) ($entry['provincia'] ?? ''))
            . '|' . strtoupper((string) ($entry['belfiore'] ?? ''));
        if ($entryKey === $key) {
            return;
        }
    }
    if (count($report['missing_comuni']) >= 20) {
        $report['missing_comuni_truncated'] = true;
        return;
    }
    $report['missing_comuni'][] = [
        'name' => $name !== '' ? $name : 'Comune sconosciuto',
        'provincia' => strtoupper($provincia),
        'belfiore' => $code,
    ];
}

function analyticspro_enrichment_report_add_final_failure(array &$report, array $parcel, string $code, string $note, ?string $belfiore = null): void
{
    $report['failure_codes'][$code] = (int) ($report['failure_codes'][$code] ?? 0) + 1;
    if (count($report['unresolved_rows']) >= 100) {
        $report['truncated'] = true;
    } else {
        $comune = trim((string) ($parcel['comune'] ?? ''));
        $foglio = trim((string) ($parcel['foglio'] ?? ''));
        $part   = trim((string) ($parcel['particella'] ?? ''));
        $label  = trim(($comune !== '' ? $comune : 'Comune sconosciuto')
            . ' F.' . ($foglio !== '' ? $foglio : '?')
            . ' P.' . ($part !== '' ? $part : '?'));
        $report['unresolved_rows'][] = $label . ' — ' . $code . ($note !== '' ? ': ' . $note : '');
    }
    if ($code === 'comune_non_indicizzato') {
        analyticspro_enrichment_report_add_missing_comune($report, $parcel, $belfiore);
    }
}

function analyticspro_enrichment_report_count_bucket(array $bucket): int
{
    $total = 0;
    foreach ($bucket as $value) {
        $total += max(0, (int) $value);
    }

    return $total;
}

/**
 * @return array{processed:int,total:int,remaining:int,resolved:int,unresolved:int,done:bool}
 */
function analyticspro_enrichment_progress_payload(int $totalUnique, int $remainingUnique, int $resolved, int $unresolved): array
{
    $resolved = max(0, $resolved);
    $unresolved = max(0, $unresolved);
    $processed = $resolved + $unresolved;
    $remainingUnique = max(0, $remainingUnique);
    $totalUnique = max($processed + $remainingUnique, $totalUnique);
    $processed = min($processed, $totalUnique);

    return [
        'processed' => $processed,
        'total' => $totalUnique,
        'remaining' => min($remainingUnique, $totalUnique),
        'resolved' => min($resolved, $totalUnique),
        'unresolved' => min($unresolved, $totalUnique),
        'done' => $remainingUnique === 0,
    ];
}

/**
 * @return array{total:int,recoverable:int,exhausted:int,unique_parcels:int,unique_parcels_recoverable:int}
 */
function analyticspro_missing_coordinate_stats_normalize(int $total, int $recoverable, int $uniqueParcels = 0, int $uniqueRecoverable = 0): array
{
    $total = max(0, $total);
    $recoverable = max(0, min($recoverable, $total));
    $uniqueParcels = max(0, $uniqueParcels);
    $uniqueRecoverable = max(0, min($uniqueRecoverable, $uniqueParcels));

    return [
        'total' => $total,
        'recoverable' => $recoverable,
        'exhausted' => max(0, $total - $recoverable),
        'unique_parcels' => $uniqueParcels,
        'unique_parcels_recoverable' => $uniqueRecoverable,
    ];
}

/**
 * @return array{processed:int,total:int,remaining:int}
 */
function analyticspro_enrichment_reconcile_progress_values(int $totalUnique, int $remainingUnique): array
{
    $totalUnique = max(0, $totalUnique);
    $remainingUnique = max(0, min($remainingUnique, $totalUnique));
    $processed = max(0, $totalUnique - $remainingUnique);
    $total = max($processed, $processed + $remainingUnique);

    return [
        'processed' => $processed,
        'total' => $total,
        'remaining' => $remainingUnique,
    ];
}

/**
 * @return array{next_attempts:int,mark_unresolved:bool}
 */
function analyticspro_enrichment_failure_transition(int $currentAttempts, int $maxAttempts): array
{
    $maxAttempts = max(1, $maxAttempts);
    $nextAttempts = max(0, $currentAttempts) + 1;

    return [
        'next_attempts' => $nextAttempts,
        'mark_unresolved' => $nextAttempts >= $maxAttempts,
    ];
}

function analyticspro_enrichment_parcel_group_by(bool $globalMode): string
{
    return $globalMode
        ? 'user_id, provincia, comune, cod_catastale, sezione, foglio, particella'
        : 'provincia, comune, cod_catastale, sezione, foglio, particella';
}

/**
 * @param array<string,mixed> $params
 */
function analyticspro_enrichment_scope_where(int $batchId, ?int $tenantId, array &$params, bool $missingOnly = true, bool $eligibleOnly = false): string
{
    $globalMode = ($batchId === 0);
    $clauses = [];

    if ($globalMode) {
        if ($tenantId !== null) {
            $clauses[] = 'user_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }
    } else {
        $clauses[] = 'import_batch_id = :batch_id';
        $params['batch_id'] = $batchId;
    }

    if ($missingOnly) {
        $clauses[] = 'lat IS NULL';
    }

    if ($eligibleOnly) {
        if (analyticspro_properties_has_coord_source_column()) {
            $clauses[] = "(coord_source IS NULL OR coord_source <> 'unresolved')";
        }
        if (analyticspro_properties_has_enrichment_attempt_columns()) {
            $clauses[] = 'enrichment_attempts < :max_attempts';
            $params['max_attempts'] = (int) ANALYTICSPRO_ENRICH_MAX_ATTEMPTS;
        }
    }

    return $clauses !== [] ? implode(' AND ', $clauses) : '1=1';
}

function analyticspro_enrichment_count_unique_parcels(PDO $pdo, int $batchId, ?int $tenantId = null, bool $missingOnly = true, bool $eligibleOnly = false): int
{
    $params = [];
    $where = analyticspro_enrichment_scope_where($batchId, $tenantId, $params, $missingOnly, $eligibleOnly);
    $sql = 'SELECT COUNT(*) FROM (
        SELECT 1 FROM properties
        WHERE ' . $where . '
        GROUP BY ' . analyticspro_enrichment_parcel_group_by($batchId === 0) . '
    ) t';
    $stmt = $pdo->prepare($sql);
    foreach ($params as $name => $value) {
        $stmt->bindValue(':' . $name, $value, PDO::PARAM_INT);
    }
    $stmt->execute();

    return (int) $stmt->fetchColumn();
}

/**
 * @return array<int,array<string,mixed>>
 */
function analyticspro_enrichment_fetch_unique_parcels(PDO $pdo, int $batchId, int $limit, ?int $tenantId = null, bool $eligibleOnly = true): array
{
    $params = [];
    $where = analyticspro_enrichment_scope_where($batchId, $tenantId, $params, true, $eligibleOnly);
    $globalMode = ($batchId === 0);
    $select = $globalMode
        ? 'user_id, provincia, comune, cod_catastale, sezione, foglio, particella'
        : 'provincia, comune, cod_catastale, sezione, foglio, particella';
    $sql = 'SELECT ' . $select . '
        FROM properties
        WHERE ' . $where . '
        GROUP BY ' . analyticspro_enrichment_parcel_group_by($globalMode) . '
        ORDER BY ' . ($globalMode ? 'user_id ASC, ' : '') . 'provincia ASC, comune ASC, foglio ASC, particella ASC
        LIMIT :lim';
    $stmt = $pdo->prepare($sql);
    foreach ($params as $name => $value) {
        $stmt->bindValue(':' . $name, $value, PDO::PARAM_INT);
    }
    $stmt->bindValue(':lim', max(1, $limit), PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll() ?: [];
}

/**
 * @return array{processed:int,total:int,remaining:int}
 */
function analyticspro_enrichment_fetch_progress(PDO $pdo, int $batchId, ?int $tenantId = null): array
{
    $totalUnique = analyticspro_enrichment_count_unique_parcels(
        $pdo,
        $batchId,
        $tenantId,
        $batchId === 0,
        false
    );
    $remainingUnique = analyticspro_enrichment_count_unique_parcels($pdo, $batchId, $tenantId, true, true);

    return analyticspro_enrichment_reconcile_progress_values($totalUnique, $remainingUnique);
}

/**
 * @return array{total_rows:int,geolocated_rows:int,missing_rows:int}
 */
function analyticspro_enrichment_fetch_reconciliation(PDO $pdo, int $batchId): array
{
    $stmt = $pdo->prepare(
        'SELECT
            COUNT(*) AS total_rows,
            SUM(CASE WHEN lat IS NOT NULL THEN 1 ELSE 0 END) AS geolocated_rows,
            SUM(CASE WHEN lat IS NULL THEN 1 ELSE 0 END) AS missing_rows
         FROM properties
         WHERE import_batch_id = :batch_id'
    );
    $stmt->execute(['batch_id' => $batchId]);
    $row = $stmt->fetch() ?: [];

    return [
        'total_rows' => (int) ($row['total_rows'] ?? 0),
        'geolocated_rows' => (int) ($row['geolocated_rows'] ?? 0),
        'missing_rows' => (int) ($row['missing_rows'] ?? 0),
    ];
}

/**
 * @param array<string,mixed> $parcel
 * @return array<string,mixed>
 */
function analyticspro_enrichment_parcel_match_params(array $parcel, int $batchId, ?int $tenantId = null): array
{
    $params = [
        'provincia' => (string) ($parcel['provincia'] ?? ''),
        'comune' => (string) ($parcel['comune'] ?? ''),
        'sezione' => $parcel['sezione'] !== null ? (string) $parcel['sezione'] : null,
        'foglio' => (string) ($parcel['foglio'] ?? ''),
        'particella' => (string) ($parcel['particella'] ?? ''),
    ];
    if ($batchId > 0) {
        $params['batch_id'] = $batchId;
    } elseif ($tenantId !== null) {
        $params['tenant_id'] = $tenantId;
    } elseif (isset($parcel['user_id'])) {
        $params['user_id'] = (int) $parcel['user_id'];
    }

    return $params;
}

function analyticspro_enrichment_parcel_match_where(int $batchId, ?int $tenantId = null, bool $useResolvedTenant = false): string
{
    $clauses = [];
    if ($batchId > 0) {
        $clauses[] = 'import_batch_id = :batch_id';
    } elseif ($tenantId !== null) {
        $clauses[] = 'user_id = :tenant_id';
    } elseif ($useResolvedTenant) {
        $clauses[] = 'user_id = :user_id';
    }

    $clauses[] = 'provincia = :provincia';
    $clauses[] = 'comune = :comune';
    $clauses[] = '(sezione <=> :sezione)';
    $clauses[] = 'foglio = :foglio';
    $clauses[] = 'particella = :particella';
    $clauses[] = 'lat IS NULL';

    return implode(' AND ', $clauses);
}

function analyticspro_enrichment_fetch_attempt_count(PDO $pdo, int $batchId, array $parcel, ?int $tenantId = null): int
{
    if (!analyticspro_properties_has_enrichment_attempt_columns()) {
        return 0;
    }

    $params = analyticspro_enrichment_parcel_match_params($parcel, $batchId, $tenantId);
    $stmt = $pdo->prepare(
        'SELECT COALESCE(MAX(enrichment_attempts), 0)
         FROM properties
         WHERE ' . analyticspro_enrichment_parcel_match_where($batchId, $tenantId, $batchId === 0 && $tenantId === null)
    );
    $stmt->execute($params);

    return (int) $stmt->fetchColumn();
}

function analyticspro_enrichment_mark_parcel_failure(PDO $pdo, int $batchId, array $parcel, string $code, string $note, ?int $tenantId = null): bool
{
    $hasAttempts = analyticspro_properties_has_enrichment_attempt_columns();
    $hasCoordSource = analyticspro_properties_has_coord_source_column();

    if (!$hasAttempts && !$hasCoordSource) {
        return false;
    }

    $transition = analyticspro_enrichment_failure_transition(
        analyticspro_enrichment_fetch_attempt_count($pdo, $batchId, $parcel, $tenantId),
        (int) ANALYTICSPRO_ENRICH_MAX_ATTEMPTS
    );
    $params = analyticspro_enrichment_parcel_match_params($parcel, $batchId, $tenantId);

    $sets = [];
    if ($hasAttempts) {
        $sets[] = 'enrichment_attempts = enrichment_attempts + 1';
        $sets[] = 'enrichment_last_attempt_at = NOW()';
        $sets[] = 'enrichment_last_error_code = :code';
        $sets[] = 'enrichment_last_error_note = :note';
        $params['code'] = mb_substr($code, 0, 64, 'UTF-8');
        $params['note'] = mb_substr($note, 0, 255, 'UTF-8');
    }
    if ($hasCoordSource && ($transition['mark_unresolved'] || !$hasAttempts)) {
        $sets[] = "coord_source = 'unresolved'";
    }

    if ($sets !== []) {
        $pdo->prepare(
            'UPDATE properties SET ' . implode(', ', $sets) . '
             WHERE ' . analyticspro_enrichment_parcel_match_where($batchId, $tenantId, $batchId === 0 && $tenantId === null)
        )->execute($params);
    }

    return $transition['mark_unresolved'] || ($hasCoordSource && !$hasAttempts);
}

/**
 * @return array{total:int,recoverable:int,exhausted:int,unique_parcels:int,unique_parcels_recoverable:int}
 */
function analyticspro_fetch_missing_coordinate_stats(?int $tenantId = null): array
{
    $pdo = analyticspro_db();
    $clauses = ['lat IS NULL'];
    $params = [];
    if ($tenantId !== null) {
        $clauses[] = 'user_id = :tenant_id';
        $params['tenant_id'] = $tenantId;
    }
    $where = implode(' AND ', $clauses);

    $recoverable = ['lat IS NULL'];
    if ($tenantId !== null) {
        $recoverable[] = 'user_id = :tenant_id';
    }
    if (analyticspro_properties_has_coord_source_column()) {
        $recoverable[] = "(coord_source IS NULL OR coord_source <> 'unresolved')";
    }
    if (analyticspro_properties_has_enrichment_attempt_columns()) {
        $recoverable[] = 'COALESCE(enrichment_attempts, 0) < :max_attempts';
        $params['max_attempts'] = (int) ANALYTICSPRO_ENRICH_MAX_ATTEMPTS;
    }

    $stmt = $pdo->prepare(
        'SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN ' . implode(' AND ', $recoverable) . ' THEN 1 ELSE 0 END) AS recoverable
         FROM properties
         WHERE ' . $where
    );
    foreach ($params as $name => $value) {
        $stmt->bindValue(':' . $name, $value, PDO::PARAM_INT);
    }
    $stmt->execute();
    $row = $stmt->fetch() ?: [];
    $total = (int) ($row['total'] ?? 0);
    $recoverableCount = (int) ($row['recoverable'] ?? 0);
    $uniqueParcels = analyticspro_enrichment_count_unique_parcels($pdo, 0, $tenantId, true, false);
    $uniqueRecoverable = analyticspro_enrichment_count_unique_parcels($pdo, 0, $tenantId, true, true);

    return analyticspro_missing_coordinate_stats_normalize($total, $recoverableCount, $uniqueParcels, $uniqueRecoverable);
}

function analyticspro_enrichment_recoverable_condition_sql(string $alias = ''): string
{
    $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';
    $clauses = [$prefix . 'lat IS NULL'];
    if (analyticspro_properties_has_coord_source_column()) {
        $clauses[] = '(' . $prefix . "coord_source IS NULL OR " . $prefix . "coord_source <> 'unresolved')";
    }
    if (analyticspro_properties_has_enrichment_attempt_columns()) {
        $clauses[] = 'COALESCE(' . $prefix . 'enrichment_attempts, 0) < ' . (int) ANALYTICSPRO_ENRICH_MAX_ATTEMPTS;
    }

    return implode(' AND ', $clauses);
}

function analyticspro_enrichment_unique_parcel_expr(string $alias = ''): string
{
    $prefix = $alias !== '' ? rtrim($alias, '.') . '.' : '';

    return "CONCAT_WS('|', COALESCE({$prefix}provincia, ''), COALESCE({$prefix}comune, ''), COALESCE({$prefix}cod_catastale, ''), COALESCE({$prefix}sezione, ''), COALESCE({$prefix}foglio, ''), COALESCE({$prefix}particella, ''))";
}

function analyticspro_enrichment_count_unresolved_unique_parcels(PDO $pdo, int $batchId, ?int $tenantId = null): int
{
    if (!analyticspro_properties_has_coord_source_column() && !analyticspro_properties_has_enrichment_attempt_columns()) {
        return 0;
    }

    $params = [];
    $where = analyticspro_enrichment_scope_where($batchId, $tenantId, $params, true, false);
    $clauses = [$where];
    if (analyticspro_properties_has_coord_source_column()) {
        $clauses[] = "coord_source = 'unresolved'";
    } else {
        $clauses[] = 'COALESCE(enrichment_attempts, 0) >= :max_attempts';
        $params['max_attempts'] = (int) ANALYTICSPRO_ENRICH_MAX_ATTEMPTS;
    }
    $sql = 'SELECT COUNT(*) FROM (
        SELECT 1 FROM properties
        WHERE ' . implode(' AND ', $clauses) . '
        GROUP BY ' . analyticspro_enrichment_parcel_group_by($batchId === 0) . '
    ) t';
    $stmt = $pdo->prepare($sql);
    foreach ($params as $name => $value) {
        $stmt->bindValue(':' . $name, $value, PDO::PARAM_INT);
    }
    $stmt->execute();

    return (int) $stmt->fetchColumn();
}

/**
 * @return array{lat:?float,lng:?float,coord_source:?string,belfiore:?string,belfiore_source:?string,failure_code:?string,failure_note:?string}
 */
function analyticspro_resolve_parcel_coordinates(array $property, array &$memo): array
{
    require_once __DIR__ . '/wfs_lookup.php';
    require_once __DIR__ . '/zornade_lookup.php';
    require_once __DIR__ . '/gml_catalog.php';

    $memo['belfiore_map'] ??= [];
    $memo['parcel_map'] ??= [];
    $memo['stats'] ??= ['gml_lookup_calls' => 0];
    $memo['last_wfs_call_at'] ??= 0.0;
    $memo['last_zornade_call_at'] ??= 0.0;

    $provincia       = trim((string) ($property['provincia'] ?? ''));
    $comune          = trim((string) ($property['comune'] ?? ''));
    $codCatInput     = strtoupper(trim((string) ($property['cod_catastale'] ?? '')));
    $sezione         = $property['sezione'] ?? null;
    $sezione         = $sezione !== null ? trim((string) $sezione) : null;
    $foglioInput     = trim((string) ($property['foglio'] ?? ''));
    $particellaInput = trim((string) ($property['particella'] ?? ''));

    if ($foglioInput === '' || $particellaInput === '') {
        return [
            'lat' => null, 'lng' => null, 'coord_source' => null,
            'belfiore' => null, 'belfiore_source' => null,
            'failure_code' => 'dati_incompleti',
            'failure_note' => 'Foglio o particella mancanti nella riga importata',
        ];
    }

    $belfiore = null;
    $belfioreSource = null;
    $belfioreNote = '';
    if (analyticspro_is_valid_cod_catastale($codCatInput)) {
        $belfiore = $codCatInput;
        $belfioreSource = 'esplicito';
    } else {
        $comuneKey = analyticspro_gml_norm_nome_comune($comune) . '|' . analyticspro_gml_norm_provincia($provincia);
        if (array_key_exists($comuneKey, $memo['belfiore_map'])) {
            $cached = $memo['belfiore_map'][$comuneKey];
            $belfiore = $cached['cod'];
            $belfioreSource = $cached['source'];
            $belfioreNote = (string) ($cached['note'] ?? '');
        } else {
            if ($comune !== '') {
                $gmlCode = analyticspro_gml_belfiore_da_comune($comune, $provincia);
                if (is_string($gmlCode) && $gmlCode !== '') {
                    $belfiore = $gmlCode;
                    $belfioreSource = 'gml_nomefile';
                    $belfioreNote = 'Codice risolto dai nomi file GML locali';
                }
            }
            if ($belfiore === null) {
                $resolved = analyticspro_resolve_cod_catastale('', $comune, $provincia);
                $code = $resolved['cod'] ?? null;
                if (is_string($code) && analyticspro_is_valid_cod_catastale($code)) {
                    $belfiore = strtoupper($code);
                    $belfioreSource = ($resolved['source'] ?? '') === 'gml_catalogo'
                        ? 'gml_nomefile'
                        : (string) ($resolved['source'] ?? 'non_risolto');
                    $belfioreNote = (string) ($resolved['note'] ?? '');
                } else {
                    $belfioreNote = (string) ($resolved['note'] ?? '');
                    $belfioreSource = (string) ($resolved['source'] ?? 'non_risolto');
                }
            }
            $memo['belfiore_map'][$comuneKey] = [
                'cod' => $belfiore,
                'source' => $belfioreSource,
                'note' => $belfioreNote,
            ];
        }
    }

    if (!is_string($belfiore) || $belfiore === '') {
        return [
            'lat' => null, 'lng' => null, 'coord_source' => null,
            'belfiore' => null, 'belfiore_source' => $belfioreSource,
            'failure_code' => 'comune_non_risolto',
            'failure_note' => $belfioreNote !== '' ? $belfioreNote : 'Comune non risolto',
        ];
    }

    $foglioKey = analyticspro_wfs_normalize_token($foglioInput);
    $partKey   = analyticspro_gml_norm_particella($particellaInput);
    $parcelKey = $belfiore . '|' . $foglioKey . '|' . $partKey;
    if (array_key_exists($parcelKey, $memo['parcel_map'])) {
        return $memo['parcel_map'][$parcelKey];
    }

    $memo['stats']['gml_lookup_calls'] = (int) ($memo['stats']['gml_lookup_calls'] ?? 0) + 1;
    $gmlResult = analyticspro_gml_lookup($belfiore, $foglioInput, $particellaInput);
    if ($gmlResult !== null) {
        $result = [
            'lat' => (float) $gmlResult['lat'],
            'lng' => (float) $gmlResult['lon'],
            'coord_source' => 'gml_locale',
            'belfiore' => $belfiore,
            'belfiore_source' => $belfioreSource,
            'failure_code' => null,
            'failure_note' => null,
        ];
        $memo['parcel_map'][$parcelKey] = $result;
        return $result;
    }

    $gmlDiag = analyticspro_gml_diagnose_lookup($belfiore, $foglioInput, $particellaInput);
    $foglioRemote = analyticspro_wfs_normalize_token($foglioInput);
    $particellaRemote = analyticspro_wfs_normalize_token($particellaInput);

    $cached = null;
    $db = null;
    if ($foglioRemote !== '' && $particellaRemote !== '') {
        try {
            $db = analyticspro_wfs_open_cache_db();
            $cached = analyticspro_wfs_get_cached_particella($db, $belfiore, $foglioRemote, $particellaRemote);
            if ($cached !== null) {
                $db->close();
                $db = null;
            }
        } catch (Throwable) {
            $db = null;
        }
    }
    if ($cached !== null && ($cached['ok'] ?? false)) {
        $result = [
            'lat' => (float) $cached['lat'],
            'lng' => (float) $cached['lng'],
            'coord_source' => 'cache',
            'belfiore' => $belfiore,
            'belfiore_source' => $belfioreSource,
            'failure_code' => null,
            'failure_note' => null,
        ];
        $memo['parcel_map'][$parcelKey] = $result;
        return $result;
    }

    $resolvedData = null;
    if ($foglioRemote !== '' && $particellaRemote !== '' && $comune !== '' && $provincia !== '') {
        $elapsed = microtime(true) - (float) $memo['last_zornade_call_at'];
        if ((float) $memo['last_zornade_call_at'] > 0.0 && $elapsed < 0.40) {
            usleep((int) ((0.40 - $elapsed) * 1_000_000));
        }
        $zornade = analyticspro_zornade_lookup_particella($comune, $provincia, $foglioRemote, $particellaRemote, $sezione);
        $memo['last_zornade_call_at'] = microtime(true);
        if ($zornade !== null && ($zornade['ok'] ?? false)) {
            $resolvedData = $zornade;
        }
    }
    if ($resolvedData === null && $foglioRemote !== '' && $particellaRemote !== '') {
        $elapsed = microtime(true) - (float) $memo['last_wfs_call_at'];
        if ((float) $memo['last_wfs_call_at'] > 0.0 && $elapsed < 0.50) {
            usleep((int) ((0.50 - $elapsed) * 1_000_000));
        }
        $wfsData = analyticspro_wfs_query_service($belfiore, $foglioRemote, $particellaRemote);
        $memo['last_wfs_call_at'] = microtime(true);
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
                analyticspro_wfs_save_cached_particella($db, $belfiore, $foglioRemote, $particellaRemote, $resolvedData);
            }
            $db->close();
        }

        $source = (string) ($resolvedData['source'] ?? 'WFS-AdE');
        $result = [
            'lat' => (float) $resolvedData['lat'],
            'lng' => (float) $resolvedData['lng'],
            'coord_source' => $source === 'Zornade' ? 'zornade' : 'wfs',
            'belfiore' => $belfiore,
            'belfiore_source' => $belfioreSource,
            'failure_code' => null,
            'failure_note' => null,
        ];
        $memo['parcel_map'][$parcelKey] = $result;
        return $result;
    }

    if ($db !== null) {
        $db->close();
    }

    $failureCode = $gmlDiag['code'] ?? null;
    $failureNote = (string) ($gmlDiag['note'] ?? '');
    if ($failureCode === 'gml_mancante') {
        $failureCode = 'provider_remoto_fallito';
        $failureNote = $failureNote . '. Anche cache/Zornade/WFS non hanno restituito coordinate';
    }
    $result = [
        'lat' => null,
        'lng' => null,
        'coord_source' => null,
        'belfiore' => $belfiore,
        'belfiore_source' => $belfioreSource,
        'failure_code' => $failureCode ?? 'provider_remoto_fallito',
        'failure_note' => $failureNote !== '' ? $failureNote : 'Lookup remoto non riuscito',
    ];
    $memo['parcel_map'][$parcelKey] = $result;
    return $result;
}

/**
 * @return array{lat:?float,lng:?float,verified:int,coord_source:?string,failure_code:?string,failure_note:string,cod_catastale:?string,resolution_source:string}
 */
function analyticspro_enrich_resolve_single_parcel(array $parcel, float &$lastWfsCallAt, float &$lastZornadeCallAt): array
{
    $memo = [
        'last_wfs_call_at' => $lastWfsCallAt,
        'last_zornade_call_at' => $lastZornadeCallAt,
    ];
    $resolved = analyticspro_resolve_parcel_coordinates($parcel, $memo);
    $lastWfsCallAt = (float) ($memo['last_wfs_call_at'] ?? 0.0);
    $lastZornadeCallAt = (float) ($memo['last_zornade_call_at'] ?? 0.0);

    return [
        'lat' => $resolved['lat'],
        'lng' => $resolved['lng'],
        'verified' => ($resolved['lat'] !== null && $resolved['lng'] !== null) ? 1 : 0,
        'coord_source' => $resolved['coord_source'],
        'failure_code' => $resolved['failure_code'],
        'failure_note' => (string) ($resolved['failure_note'] ?? ''),
        'cod_catastale' => $resolved['belfiore'],
        'resolution_source' => (string) ($resolved['belfiore_source'] ?? 'non_risolto'),
    ];
}

function analyticspro_lookup_cadastral_coordinates(array $property): array
{
    // Lookup parcel coordinates: tries Zornade first (if configured), then falls back
    // to the public AdE INSPIRE WFS service.  Results are cached in a local SQLite
    // database.  When both providers fail the function returns nulls so the import
    // can continue without aborting.
    static $memo = [];

    require_once __DIR__ . '/wfs_lookup.php';
    require_once __DIR__ . '/zornade_lookup.php';

    try {
        $resolved = analyticspro_resolve_parcel_coordinates($property, $memo);
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

function analyticspro_process_import_batch_payload(int $batchId, array $payload): array
{
    $pdo = analyticspro_db();
    $rows = $payload['rows'] ?? [];
    $tenantId = (int) ($payload['tenant_id'] ?? 0);
    $uploaderId = (int) ($payload['uploaded_by'] ?? 0);
    $uploaderName = trim((string) ($payload['uploaded_by_name'] ?? 'Import automatico'));
    $decisions = $payload['decisions'] ?? [];

    $hasPianoColumn = analyticspro_properties_has_piano_column();
    $hasProvinciaOriginaleColumn = analyticspro_properties_has_provincia_originale_column();
    $findProperty = $pdo->prepare('SELECT * FROM properties WHERE user_id = :user_id AND provincia = :provincia AND comune = :comune AND sezione <=> :sezione AND foglio = :foglio AND particella = :particella AND subalterno <=> :subalterno LIMIT 1');
    $insertColumns = [
        'user_id', 'import_batch_id', 'provincia', 'comune', 'cod_catastale', 'sezione', 'foglio', 'particella', 'subalterno',
        'indirizzo', 'civico', 'categoria', 'classe',
    ];
    if ($hasPianoColumn) {
        $insertColumns[] = 'piano';
    }
    $insertColumns = array_merge($insertColumns, ['consistenza', 'superficie', 'rendita', 'titolarita', 'quota']);
    if ($hasProvinciaOriginaleColumn) {
        $insertColumns[] = 'provincia_originale';
    }
    $insertColumns = array_merge($insertColumns, ['lat', 'lng', 'posizione_verificata', 'coord_source', 'stato', 'stato_personalizzato', 'colore_marker']);
    $insertPlaceholders = array_map(static fn (string $column): string => ':' . $column, $insertColumns);
    $insertProperty = $pdo->prepare('INSERT INTO properties (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $insertPlaceholders) . ')');

    $updateSet = [
        'import_batch_id = :import_batch_id',
        'cod_catastale = :cod_catastale',
        'indirizzo = :indirizzo',
        'civico = :civico',
        'categoria = :categoria',
        'classe = :classe',
    ];
    if ($hasPianoColumn) {
        $updateSet[] = 'piano = :piano';
    }
    $updateSet = array_merge($updateSet, [
        'consistenza = :consistenza',
        'superficie = :superficie',
        'rendita = :rendita',
        'titolarita = :titolarita',
        'quota = :quota',
    ]);
    if ($hasProvinciaOriginaleColumn) {
        $updateSet[] = 'provincia_originale = COALESCE(NULLIF(:provincia_originale, \'\'), provincia_originale)';
    }
    $updateProperty = $pdo->prepare('UPDATE properties SET ' . implode(', ', $updateSet) . ' WHERE id = :id');
    $updatePropertyWithCoords = $pdo->prepare('UPDATE properties SET ' . implode(', ', array_merge($updateSet, [
        'lat = :lat',
        'lng = :lng',
        'posizione_verificata = :posizione_verificata',
        'coord_source = :coord_source',
    ])) . ' WHERE id = :id');
    $selectCurrentOwner = $pdo->prepare('SELECT * FROM property_owners WHERE property_id = :property_id AND is_current = 1 LIMIT 1');
    $closeOwners = $pdo->prepare('UPDATE property_owners SET is_current = 0, valid_to = NOW() WHERE property_id = :property_id AND is_current = 1');
    $insertOwner = $pdo->prepare('INSERT INTO property_owners (property_id, tipo, nome_enc, cognome_enc, codice_fiscale_enc, telefono_enc, indirizzo_enc, email_enc, nome_hash, cognome_hash, codice_fiscale_hash, telefono_hash, data_nascita, luogo_nascita_enc, genere, is_current, valid_from) VALUES (:property_id, :tipo, :nome_enc, :cognome_enc, :codice_fiscale_enc, :telefono_enc, :indirizzo_enc, :email_enc, :nome_hash, :cognome_hash, :codice_fiscale_hash, :telefono_hash, :data_nascita, :luogo_nascita_enc, :genere, 1, NOW())');
    $insertConflict = $pdo->prepare('INSERT INTO import_duplicate_conflicts (import_batch_id, property_id, action_taken, resolved_by, resolved_at) VALUES (:import_batch_id, :property_id, :action_taken, :resolved_by, NOW())');
    $insertNote = $pdo->prepare('INSERT INTO property_notes (property_id, author_id, author_name_snapshot, testo) VALUES (:property_id, :author_id, :author_name_snapshot, :testo)');
    $updateBatch = $pdo->prepare('UPDATE import_batches SET processed_rows = :processed_rows WHERE id = :id');

    try {
        $processed = 0;
        $savedRows = 0;
        $skippedRows = 0;
        $notesImported = 0;
        $skippedReasons = ['missing_cadastral_fields' => 0, 'unrecognized_province' => 0];
        $warnings = [];
        foreach ($rows as $index => $row) {
            $entry = analyticspro_extract_row_payload($row, $index);
            $property = $entry['property'];
            foreach (($entry['warnings'] ?? []) as $warningMessage) {
                $warningMessage = trim((string) $warningMessage);
                if ($warningMessage !== '') {
                    $warnings[] = $warningMessage;
                }
            }
            if ($property['provincia'] === '' && trim((string) ($property['provincia_originale'] ?? '')) !== '') {
                $skippedReasons['unrecognized_province']++;
            }
            if ($property['provincia'] === '' || $property['comune'] === '' || $property['foglio'] === '' || $property['particella'] === '') {
                $processed++;
                $skippedRows++;
                $skippedReasons['missing_cadastral_fields']++;
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
                $hasManualCoords = $property['lat'] !== null && $property['lng'] !== null;
                $updateParams = [
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
                ] + ($hasPianoColumn ? ['piano' => $property['piano'] !== '' ? $property['piano'] : null] : []);
                if ($hasProvinciaOriginaleColumn) {
                    $updateParams['provincia_originale'] = (string) ($property['provincia_originale'] ?? '');
                }
                if ($hasManualCoords) {
                    $updatePropertyWithCoords->execute($updateParams + [
                        'lat' => $property['lat'],
                        'lng' => $property['lng'],
                        'posizione_verificata' => 1,
                        'coord_source' => 'manual',
                    ]);
                } else {
                    $updateProperty->execute($updateParams);
                }
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
                            'luogo_nascita_enc' => analyticspro_encrypt($entry['owner']['luogo_nascita'] ?? ''),
                            'genere' => $entry['owner']['genere'],
                        ]);
                    }
                }

            } else {
                $insertParams = [
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
                    'lat' => $property['lat'],
                    'lng' => $property['lng'],
                    'posizione_verificata' => ($property['lat'] !== null && $property['lng'] !== null) ? 1 : 0,
                    'coord_source' => ($property['lat'] !== null && $property['lng'] !== null) ? 'manual' : null,
                    'stato' => null,
                    'stato_personalizzato' => null,
                    'colore_marker' => '#0d6efd',
                ] + ($hasPianoColumn ? ['piano' => $property['piano'] !== '' ? $property['piano'] : null] : []);
                if ($hasProvinciaOriginaleColumn) {
                    $insertParams['provincia_originale'] = (string) ($property['provincia_originale'] ?? '');
                }
                $insertProperty->execute($insertParams);
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
                    'luogo_nascita_enc' => analyticspro_encrypt($entry['owner']['luogo_nascita'] ?? ''),
                    'genere' => $entry['owner']['genere'],
                ]);
            }

            $note = trim((string) ($entry['note'] ?? ''));
            if ($note !== '') {
                $insertNote->execute([
                    'property_id' => $propertyId,
                    'author_id' => $uploaderId,
                    'author_name_snapshot' => $uploaderName !== '' ? $uploaderName : 'Import automatico',
                    'testo' => $note,
                ]);
                $notesImported++;
            }

            $processed++;
            $savedRows++;
            $updateBatch->execute(['processed_rows' => $processed, 'id' => $batchId]);
        }

        $pendingEnrichment = analyticspro_enrichment_count_unique_parcels($pdo, $batchId, null, true, true);

        $pdo->prepare("UPDATE import_batches SET status = 'completed', completed_at = NOW(), processed_rows = total_rows, enrichment_total = :enrichment_total WHERE id = :id")
            ->execute([
                'enrichment_total' => $pendingEnrichment,
                'id' => $batchId,
            ]);
        return [
            'processed_rows' => $processed,
            'saved_rows' => $savedRows,
            'skipped_rows' => $skippedRows,
            'notes_imported' => $notesImported,
            'skipped_reasons' => array_filter($skippedReasons),
            'warnings' => array_values(array_unique($warnings)),
        ];
    } catch (Throwable $exception) {
        $pdo->prepare("UPDATE import_batches SET status = 'failed', error_message = :message, completed_at = NOW() WHERE id = :id")
            ->execute(['message' => $exception->getMessage(), 'id' => $batchId]);
        throw $exception;
    }
}

/**
 * Esegue la geolocalizzazione sincrona post-import per un batch, con soglia di sicurezza
 * sul numero di particelle uniche da processare nella stessa richiesta HTTP.
 *
 * @return array{saved_rows:int,geolocated:int,total_unique:int,processed_unique:int,remaining_unique:int,done:bool,enrichment_sync:bool,coord_source:array<string,int>,attempt_failures:array<string,int>,failure_codes:array<string,int>,unresolved_rows:array<int,string>,truncated:bool,missing_comuni:array<int,array{name:string,provincia:string,belfiore:string}>,missing_comuni_truncated:bool,resolved:int,unresolved:int,total_rows:int,geolocated_rows:int,missing_rows:int}
 */
function analyticspro_enrich_batch_coordinates_sync(int $batchId, int $maxUnique = 2000): array
{
    $pdo = analyticspro_db();
    $report = analyticspro_enrichment_report_default();
    $maxUnique = max(1, $maxUnique);
    $totalUnique = analyticspro_enrichment_count_unique_parcels($pdo, $batchId, null, false, false);
    $remainingEligible = analyticspro_enrichment_count_unique_parcels($pdo, $batchId, null, true, true);
    $initialProgress = analyticspro_enrichment_reconcile_progress_values($totalUnique, $remainingEligible);

    $pdo->prepare(
        'UPDATE import_batches
         SET enrichment_status = :status,
             enrichment_processed = :processed,
             enrichment_total = :total,
             enrichment_sync = :sync,
             enrichment_report = :report
         WHERE id = :id'
    )->execute([
        'status' => $remainingEligible === 0 ? 'completed' : 'processing',
        'processed' => $initialProgress['processed'],
        'total' => $initialProgress['total'],
        'sync' => $remainingEligible > $maxUnique ? 1 : 0,
        'report' => json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'id' => $batchId,
    ]);

    if ($remainingEligible === 0) {
        $savedStmt = $pdo->prepare('SELECT processed_rows FROM import_batches WHERE id = :id');
        $savedStmt->execute(['id' => $batchId]);
        $savedRows = (int) $savedStmt->fetchColumn();
        $reconciliation = analyticspro_enrichment_fetch_reconciliation($pdo, $batchId);
        $unresolvedUnique = analyticspro_enrichment_count_unresolved_unique_parcels($pdo, $batchId);
        $resolvedUnique = max(0, $totalUnique - $unresolvedUnique);

        return [
            'saved_rows' => $savedRows,
            'geolocated' => 0,
            'total_unique' => $totalUnique,
            'processed_unique' => $initialProgress['processed'],
            'remaining_unique' => 0,
            'done' => true,
            'enrichment_sync' => false,
            'coord_source' => [],
            'attempt_failures' => [],
            'failure_codes' => [],
            'unresolved_rows' => [],
            'truncated' => false,
            'missing_comuni' => [],
            'missing_comuni_truncated' => false,
            'resolved' => $resolvedUnique,
            'unresolved' => $unresolvedUnique,
            'total_rows' => $reconciliation['total_rows'],
            'geolocated_rows' => $reconciliation['geolocated_rows'],
            'missing_rows' => $reconciliation['missing_rows'],
        ];
    }

    $uniqueParcels = analyticspro_enrichment_fetch_unique_parcels($pdo, $batchId, min($remainingEligible, $maxUnique));
    $updateStmt = $pdo->prepare(
        'UPDATE properties
         SET lat = :lat, lng = :lng, posizione_verificata = :verified, coord_source = :coord_source
         WHERE ' . analyticspro_enrichment_parcel_match_where($batchId, null, false)
    );

    $memo = [];
    $geolocated = 0;
    $loopCount = 0;

    foreach ($uniqueParcels as $parcel) {
        try {
            $resolved = analyticspro_resolve_parcel_coordinates($parcel, $memo);
            if ($resolved['lat'] !== null && $resolved['lng'] !== null) {
                $params = analyticspro_enrichment_parcel_match_params($parcel, $batchId);
                $params['lat'] = $resolved['lat'];
                $params['lng'] = $resolved['lng'];
                $params['verified'] = 1;
                $params['coord_source'] = $resolved['coord_source'];
                try {
                    $updateStmt->execute($params);
                } catch (Throwable $dbEx) {
                    $sqlState = $dbEx instanceof \PDOException ? $dbEx->getCode() : '';
                    if ($sqlState === '42S22' || str_contains($dbEx->getMessage(), 'coord_source')) {
                        $fallback = array_diff_key($params, ['coord_source' => true]);
                        $pdo->prepare(
                            'UPDATE properties
                             SET lat = :lat, lng = :lng, posizione_verificata = :verified
                             WHERE ' . analyticspro_enrichment_parcel_match_where($batchId, null, false)
                        )->execute($fallback);
                    } else {
                        throw $dbEx;
                    }
                }
                $geolocated++;
                analyticspro_enrichment_report_add_success($report, (string) ($resolved['coord_source'] ?? ''));
            } else {
                $failureCode = (string) ($resolved['failure_code'] ?? 'provider_remoto_fallito');
                $failureNote = (string) ($resolved['failure_note'] ?? '');
                analyticspro_enrichment_report_add_attempt_failure($report, $failureCode);
                if (analyticspro_enrichment_mark_parcel_failure($pdo, $batchId, $parcel, $failureCode, $failureNote)) {
                    analyticspro_enrichment_report_add_final_failure($report, $parcel, $failureCode, $failureNote, $resolved['belfiore'] ?? null);
                }
            }
        } catch (Throwable $exception) {
            analyticspro_enrichment_report_add_attempt_failure($report, 'provider_remoto_fallito');
            if (analyticspro_enrichment_mark_parcel_failure($pdo, $batchId, $parcel, 'provider_remoto_fallito', $exception->getMessage())) {
                analyticspro_enrichment_report_add_final_failure($report, $parcel, 'provider_remoto_fallito', $exception->getMessage());
            }
            error_log('[enrich_sync] Errore per '
                . (($parcel['comune'] ?? '') ?: 'N/D') . '/' . ($parcel['foglio'] ?? '') . '/' . ($parcel['particella'] ?? '')
                . ': ' . $exception->getMessage());
        }

        $loopCount++;
        if (function_exists('set_time_limit') && ($loopCount % 50) === 0) {
            @set_time_limit(20);
        }
    }

    $progress = analyticspro_enrichment_fetch_progress($pdo, $batchId);
    $done = ($progress['remaining'] === 0);

    $pdo->prepare(
        'UPDATE import_batches
         SET enrichment_status = :status,
             enrichment_processed = :processed,
             enrichment_total = :total,
             enrichment_sync = :sync,
             enrichment_report = :report
         WHERE id = :id'
    )->execute([
        'status' => $done ? 'completed' : 'processing',
        'processed' => $progress['processed'],
        'total' => $progress['total'],
        'sync' => $done ? 0 : 1,
        'report' => json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'id' => $batchId,
    ]);

    $savedStmt = $pdo->prepare('SELECT processed_rows FROM import_batches WHERE id = :id');
    $savedStmt->execute(['id' => $batchId]);
    $savedRows = (int) $savedStmt->fetchColumn();
    $reconciliation = analyticspro_enrichment_fetch_reconciliation($pdo, $batchId);
    $unresolvedUnique = analyticspro_enrichment_count_unresolved_unique_parcels($pdo, $batchId);
    $resolvedUnique = max(0, $progress['total'] - $progress['remaining'] - $unresolvedUnique);

    return [
        'saved_rows' => $savedRows,
        'geolocated' => $geolocated,
        'total_unique' => $progress['total'],
        'processed_unique' => $progress['processed'],
        'remaining_unique' => $progress['remaining'],
        'done' => $done,
        'enrichment_sync' => !$done,
        'coord_source' => $report['coord_source'],
        'attempt_failures' => $report['attempt_failures'],
        'failure_codes' => $report['failure_codes'],
        'unresolved_rows' => $report['unresolved_rows'],
        'truncated' => (bool) $report['truncated'],
        'missing_comuni' => $report['missing_comuni'],
        'missing_comuni_truncated' => (bool) $report['missing_comuni_truncated'],
        'resolved' => $resolvedUnique,
        'unresolved' => $unresolvedUnique,
        'total_rows' => $reconciliation['total_rows'],
        'geolocated_rows' => $reconciliation['geolocated_rows'],
        'missing_rows' => $reconciliation['missing_rows'],
    ];
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
    $pdo = analyticspro_db();
    $report = analyticspro_enrichment_report_default();

    try {
        $done = false;
        $iterations = 0;
        while (!$done) {
            $result = analyticspro_enrich_batch_coordinates_chunk($batchId, 50);
            $done = (bool) ($result['done'] ?? false);
            $report = is_array($result['enrichment_report'] ?? null)
                ? $result['enrichment_report']
                : $report;
            $iterations++;
            if ($iterations >= 1000 && !$done) {
                throw new RuntimeException('Limite chunk raggiunto durante la geolocalizzazione del batch.');
            }
            if (function_exists('set_time_limit') && ($iterations % 20) === 0) {
                @set_time_limit(20);
            }
        }
    } catch (Throwable $outerEx) {
        error_log('[enrich_batch_coordinates] Errore fatale batch #' . $batchId . ': ' . $outerEx->getMessage());
        if ($batchId > 0) {
            $progress = analyticspro_enrichment_fetch_progress($pdo, $batchId);
            $pdo->prepare(
                'UPDATE import_batches
                 SET enrichment_status = :status,
                     enrichment_processed = :processed,
                     enrichment_total = :total,
                     enrichment_report = :report
                 WHERE id = :id'
            )->execute([
                'status' => 'failed',
                'processed' => $progress['processed'],
                'total' => $progress['total'],
                'report' => json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'id' => $batchId,
            ]);
        }
        throw $outerEx;
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
 * @return array{processed:int,total:int,remaining:int,resolved:int,unresolved:int,done:bool,status:string,enrichment_report:array{coord_source:array<string,int>,attempt_failures:array<string,int>,failure_codes:array<string,int>,unresolved_rows:array<int,string>,truncated:bool,missing_comuni:array<int,array{name:string,provincia:string,belfiore:string}>,missing_comuni_truncated:bool},total_rows?:int,geolocated_rows?:int,missing_rows?:int}
 */
function analyticspro_enrich_batch_coordinates_chunk(int $batchId, int $limit = 25, ?int $tenantId = null): array
{
    require_once __DIR__ . '/wfs_lookup.php';
    require_once __DIR__ . '/zornade_lookup.php';
    require_once __DIR__ . '/gml_catalog.php';

    $pdo = analyticspro_db();

    $globalMode = ($batchId === 0);
    $limit = max(1, $limit);

    if (!$globalMode) {
        $initialProgress = analyticspro_enrichment_fetch_progress($pdo, $batchId);
        $initStmt = $pdo->prepare(
            'UPDATE import_batches
             SET enrichment_status = :status,
                 enrichment_processed = :processed,
                 enrichment_total = :total,
                 enrichment_report = :report
             WHERE id = :bid AND enrichment_status = \'pending\''
        );
        $initStmt->execute([
            'bid' => $batchId,
            'status' => $initialProgress['remaining'] === 0 ? 'completed' : 'processing',
            'processed' => $initialProgress['processed'],
            'total' => $initialProgress['total'],
            'report' => json_encode(analyticspro_enrichment_report_default(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    $parcels = analyticspro_enrichment_fetch_unique_parcels($pdo, $batchId, $limit, $tenantId);
    $report = !$globalMode ? analyticspro_enrichment_report_load($pdo, $batchId) : analyticspro_enrichment_report_default();

    if (empty($parcels)) {
        $progress = analyticspro_enrichment_fetch_progress($pdo, $batchId, $tenantId);
        if ($globalMode) {
            $payloadProgress = analyticspro_enrichment_progress_payload($progress['total'], $progress['remaining'], $progress['processed'], 0);
            return [
                'processed' => $payloadProgress['processed'],
                'total' => $payloadProgress['total'],
                'remaining' => $payloadProgress['remaining'],
                'resolved' => $payloadProgress['resolved'],
                'unresolved' => 0,
                'done' => true,
                'status' => 'completed',
                'enrichment_report' => $report,
            ];
        }
        $pdo->prepare(
            'UPDATE import_batches
             SET enrichment_status = \'completed\',
                 enrichment_processed = :processed,
                 enrichment_total = :total
             WHERE id = :id AND enrichment_status != \'failed\''
        )->execute([
            'id' => $batchId,
            'processed' => $progress['processed'],
            'total' => $progress['total'],
        ]);
        $row = analyticspro_enrich_fetch_batch_state($pdo, $batchId);
        $unresolvedUnique = analyticspro_enrichment_count_unresolved_unique_parcels($pdo, $batchId, $tenantId);
        $resolvedUnique = max(0, $progress['total'] - $progress['remaining'] - $unresolvedUnique);
        $payloadProgress = analyticspro_enrichment_progress_payload($progress['total'], $progress['remaining'], $resolvedUnique, $unresolvedUnique);
        $reconciliation = analyticspro_enrichment_fetch_reconciliation($pdo, $batchId);
        return [
            'processed' => $payloadProgress['processed'],
            'total' => $payloadProgress['total'],
            'remaining' => $payloadProgress['remaining'],
            'resolved' => $payloadProgress['resolved'],
            'unresolved' => $payloadProgress['unresolved'],
            'done' => true,
            'status' => 'completed',
            'enrichment_report' => $row['report'],
            'total_rows' => $reconciliation['total_rows'],
            'geolocated_rows' => $reconciliation['geolocated_rows'],
            'missing_rows' => $reconciliation['missing_rows'],
        ];
    }

    $updateStmt = $pdo->prepare(
        'UPDATE properties
         SET lat = :lat, lng = :lng, posizione_verificata = :verified, coord_source = :coord_source
         WHERE ' . analyticspro_enrichment_parcel_match_where($batchId, $tenantId, $globalMode && $tenantId === null)
    );

    $memo              = [];
    $chunkProcessed    = 0;
    $chunkResolved     = 0;
    $chunkUnresolved   = 0;

    foreach ($parcels as $parcel) {
        $comune    = (string) ($parcel['comune'] ?? '');

        try {
            $resolvedCore = analyticspro_resolve_parcel_coordinates($parcel, $memo);
            $resolved = [
                'lat' => $resolvedCore['lat'],
                'lng' => $resolvedCore['lng'],
                'verified' => ($resolvedCore['lat'] !== null && $resolvedCore['lng'] !== null) ? 1 : 0,
                'coord_source' => $resolvedCore['coord_source'],
                'failure_code' => $resolvedCore['failure_code'],
                'failure_note' => (string) ($resolvedCore['failure_note'] ?? ''),
                'belfiore' => $resolvedCore['belfiore'] ?? null,
            ];
            if ($resolved['lat'] !== null && $resolved['lng'] !== null) {
                $params = analyticspro_enrichment_parcel_match_params($parcel, $batchId, $tenantId);
                $params['lat'] = $resolved['lat'];
                $params['lng'] = $resolved['lng'];
                $params['verified'] = $resolved['verified'];
                $params['coord_source'] = $resolved['coord_source'];
                try {
                    $updateStmt->execute($params);
                } catch (Throwable $dbEx) {
                    $sqlState = $dbEx instanceof \PDOException ? $dbEx->getCode() : '';
                    if ($sqlState === '42S22' || str_contains($dbEx->getMessage(), 'coord_source')) {
                        $fallbackParams = array_diff_key($params, ['coord_source' => true]);
                        $pdo->prepare(
                            'UPDATE properties SET lat = :lat, lng = :lng, posizione_verificata = :verified
                             WHERE ' . analyticspro_enrichment_parcel_match_where($batchId, $tenantId, $globalMode && $tenantId === null)
                        )->execute($fallbackParams);
                    } else {
                        throw $dbEx;
                    }
                }
                analyticspro_enrichment_report_add_success($report, (string) ($resolved['coord_source'] ?? ''));
                $chunkResolved++;
            } else {
                $failureCode = (string) ($resolved['failure_code'] ?? 'provider_remoto_fallito');
                $failureNote = (string) ($resolved['failure_note'] ?? '');
                analyticspro_enrichment_report_add_attempt_failure($report, $failureCode);
                if (analyticspro_enrichment_mark_parcel_failure($pdo, $batchId, $parcel, $failureCode, $failureNote, $tenantId)) {
                    analyticspro_enrichment_report_add_final_failure($report, $parcel, $failureCode, $failureNote, $resolved['belfiore'] ?? null);
                    $chunkUnresolved++;
                }
            }
        } catch (Throwable $exception) {
            analyticspro_enrichment_report_add_attempt_failure($report, 'provider_remoto_fallito');
            if (analyticspro_enrichment_mark_parcel_failure($pdo, $batchId, $parcel, 'provider_remoto_fallito', $exception->getMessage(), $tenantId)) {
                analyticspro_enrichment_report_add_final_failure($report, $parcel, 'provider_remoto_fallito', $exception->getMessage());
                $chunkUnresolved++;
            }
            error_log('[enrich_chunk] Errore per ' . $comune . '/' . ($parcel['foglio'] ?? '') . '/' . ($parcel['particella'] ?? '') . ': ' . $exception->getMessage());
        }

        $chunkProcessed++;
        if (function_exists('set_time_limit') && ($chunkProcessed % 25) === 0) {
            @set_time_limit(20);
        }
    }

    $progress = analyticspro_enrichment_fetch_progress($pdo, $batchId, $tenantId);
    $done = ($progress['remaining'] === 0);
    $unresolvedUnique = $globalMode
        ? $chunkUnresolved
        : analyticspro_enrichment_count_unresolved_unique_parcels($pdo, $batchId, $tenantId);
    $resolvedUnique = $globalMode
        ? $chunkResolved
        : max(0, $progress['total'] - $progress['remaining'] - $unresolvedUnique);
    $payloadProgress = $globalMode
        ? analyticspro_enrichment_progress_payload($chunkResolved + $chunkUnresolved + $progress['remaining'], $progress['remaining'], $chunkResolved, $chunkUnresolved)
        : analyticspro_enrichment_progress_payload($progress['total'], $progress['remaining'], $resolvedUnique, $unresolvedUnique);

    if ($globalMode) {
        return [
            'processed' => $payloadProgress['processed'],
            'total' => $payloadProgress['total'],
            'remaining' => $payloadProgress['remaining'],
            'resolved' => $payloadProgress['resolved'],
            'unresolved' => $payloadProgress['unresolved'],
            'done' => $payloadProgress['done'],
            'status' => $done ? 'completed' : 'processing',
            'enrichment_report' => $report,
        ];
    }

    $pdo->prepare(
        'UPDATE import_batches
         SET enrichment_processed = :processed,
             enrichment_total = :total,
             enrichment_status = :status,
             enrichment_report = :report
         WHERE id = :id AND enrichment_status != \'failed\''
    )->execute([
        'processed' => $progress['processed'],
        'total' => $progress['total'],
        'status' => $done ? 'completed' : 'processing',
        'report' => json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'id' => $batchId,
    ]);

    $row = analyticspro_enrich_fetch_batch_state($pdo, $batchId);
    $reconciliation = analyticspro_enrichment_fetch_reconciliation($pdo, $batchId);
    return [
        'processed' => $payloadProgress['processed'],
        'total' => $payloadProgress['total'],
        'remaining' => $payloadProgress['remaining'],
        'resolved' => $payloadProgress['resolved'],
        'unresolved' => $payloadProgress['unresolved'],
        'done' => $payloadProgress['done'],
        'status' => $done ? 'completed' : 'processing',
        'enrichment_report' => $row['report'],
        'total_rows' => $reconciliation['total_rows'],
        'geolocated_rows' => $reconciliation['geolocated_rows'],
        'missing_rows' => $reconciliation['missing_rows'],
    ];
}

/**
 * Legge lo stato di avanzamento enrichment dal DB.
 *
 * @return array{processed:int,total:int,report:array{coord_source:array<string,int>,attempt_failures:array<string,int>,failure_codes:array<string,int>,unresolved_rows:array<int,string>,truncated:bool,missing_comuni:array<int,array{name:string,provincia:string,belfiore:string}>,missing_comuni_truncated:bool}}
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
