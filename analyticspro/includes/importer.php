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

function analyticspro_normalize_text(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    $value = mb_strtoupper($value, 'UTF-8');
    if (class_exists('Normalizer')) {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_D);
        if (is_string($normalized)) {
            $value = $normalized;
        }
    }
    $value = preg_replace('/\p{Mn}+/u', '', $value) ?? $value;
    $value = strtr($value, [
        'À' => 'A', 'Á' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ã' => 'A', 'Å' => 'A',
        'È' => 'E', 'É' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Ì' => 'I', 'Í' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ò' => 'O', 'Ó' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Õ' => 'O',
        'Ù' => 'U', 'Ú' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'Ç' => 'C', 'Ñ' => 'N',
    ]);
    $value = preg_replace('/[^A-Z0-9\/]+/u', ' ', $value) ?? $value;
    $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

    return trim($value);
}

function analyticspro_normalize_cadastral_number(?string $value): string
{
    $value = strtoupper(trim((string) $value));
    $value = preg_replace('/\s+/u', '', $value) ?? $value;
    if ($value === '') {
        return '';
    }
    if (preg_match('/^([0-9]+)(.*)$/', $value, $matches) !== 1) {
        return $value;
    }

    $number = ltrim((string) ($matches[1] ?? ''), '0');
    if ($number === '') {
        $number = '0';
    }

    return $number . (string) ($matches[2] ?? '');
}

/**
 * Build a canonical cadastral identity shared by import, duplicate detection and merge tooling.
 *
 * @return array{tenant_id:string,provincia:string,comune:string,cod_catastale:string,sezione:string,foglio:string,particella:string,subalterno:string}
 */
function analyticspro_normalize_cadastral_identity(array $record, int|string|null $tenantId = null): array
{
    $resolvedTenantId = $tenantId;
    if ($resolvedTenantId === null || (string) $resolvedTenantId === '') {
        $resolvedTenantId = $record['tenant_id'] ?? $record['user_id'] ?? $record['parent_user_id'] ?? '';
    }

    return [
        'tenant_id' => trim((string) $resolvedTenantId),
        'provincia' => analyticspro_normalize_text((string) ($record['provincia'] ?? '')),
        'comune' => analyticspro_normalize_text((string) ($record['comune'] ?? '')),
        'cod_catastale' => analyticspro_normalize_text((string) ($record['cod_catastale'] ?? '')),
        'sezione' => analyticspro_normalize_text((string) ($record['sezione'] ?? '')),
        'foglio' => analyticspro_normalize_cadastral_number((string) ($record['foglio'] ?? '')),
        'particella' => analyticspro_normalize_cadastral_number((string) ($record['particella'] ?? '')),
        'subalterno' => analyticspro_normalize_cadastral_number((string) ($record['subalterno'] ?? '')),
    ];
}

function analyticspro_cadastral_base_bucket_key(array $identity): string
{
    return implode('|', [
        $identity['tenant_id'],
        $identity['provincia'],
        $identity['sezione'],
        $identity['foglio'],
        $identity['particella'],
    ]);
}

/**
 * @param array<int,array{record:array<string,mixed>,identity:array<string,string>,index:int}> $entries
 * @return array{entries:array<int,array{record:array<string,mixed>,identity:array<string,string>,index:int}>,cod_catastale:string,comuni:array<string,bool>}
 */
function analyticspro_make_cadastral_cluster(array $entries): array
{
    $cluster = [
        'entries' => [],
        'cod_catastale' => '',
        'comuni' => [],
    ];

    foreach ($entries as $entry) {
        $cluster['entries'][] = $entry;
        $codCatastale = $entry['identity']['cod_catastale'] ?? '';
        if ($cluster['cod_catastale'] === '' && $codCatastale !== '') {
            $cluster['cod_catastale'] = $codCatastale;
        }
        $comune = $entry['identity']['comune'] ?? '';
        if ($comune !== '') {
            $cluster['comuni'][$comune] = true;
        }
    }

    return $cluster;
}

/**
 * @param array{entries:array<int,array{record:array<string,mixed>,identity:array<string,string>,index:int}>,cod_catastale:string,comuni:array<string,bool>} $cluster
 * @param array<int,array{record:array<string,mixed>,identity:array<string,string>,index:int}> $entries
 */
function analyticspro_append_entries_to_cadastral_cluster(array &$cluster, array $entries): void
{
    foreach ($entries as $entry) {
        $cluster['entries'][] = $entry;
        $codCatastale = $entry['identity']['cod_catastale'] ?? '';
        if ($cluster['cod_catastale'] === '' && $codCatastale !== '') {
            $cluster['cod_catastale'] = $codCatastale;
        }
        $comune = $entry['identity']['comune'] ?? '';
        if ($comune !== '') {
            $cluster['comuni'][$comune] = true;
        }
    }
}

/**
 * @param array{entries:array<int,array{record:array<string,mixed>,identity:array<string,string>,index:int}>,cod_catastale:string,comuni:array<string,bool>} $cluster
 */
function analyticspro_cadastral_cluster_matches_missing_code_group(array $cluster, string $comune): bool
{
    return $comune !== '' && isset($cluster['comuni'][$comune]);
}

/**
 * @param array{entries:array<int,array{record:array<string,mixed>,identity:array<string,string>,index:int}>,cod_catastale:string,comuni:array<string,bool>} $cluster
 */
function analyticspro_cadastral_cluster_matches_missing_subalterno(array $cluster, array $identity): bool
{
    $clusterCodCatastale = (string) ($cluster['cod_catastale'] ?? '');
    $entryCodCatastale = (string) ($identity['cod_catastale'] ?? '');
    if ($clusterCodCatastale !== '' && $entryCodCatastale !== '') {
        return $clusterCodCatastale === $entryCodCatastale;
    }

    $comune = (string) ($identity['comune'] ?? '');
    return $comune !== '' && isset($cluster['comuni'][$comune]);
}

/**
 * Records with valorizzato subalterno are grouped per sub and then merged only when the
 * cod_catastale is identical, or when the unresolved group has exactly one comune-compatible
 * coded candidate.
 *
 * @param array<int,array{record:array<string,mixed>,identity:array<string,string>,index:int}> $entries
 * @return array<int,array{entries:array<int,array{record:array<string,mixed>,identity:array<string,string>,index:int}>,cod_catastale:string,comuni:array<string,bool>}>
 */
function analyticspro_build_non_empty_subalterno_clusters(array $entries): array
{
    $codedClusters = [];
    $codedOrder = [];
    $missingCodeClusters = [];
    $missingOrder = [];

    foreach ($entries as $entry) {
        $identity = $entry['identity'];
        $codCatastale = $identity['cod_catastale'];
        if ($codCatastale !== '') {
            $clusterKey = 'COD:' . $codCatastale;
            if (!isset($codedClusters[$clusterKey])) {
                $codedClusters[$clusterKey] = analyticspro_make_cadastral_cluster([]);
                $codedOrder[] = $clusterKey;
            }
            analyticspro_append_entries_to_cadastral_cluster($codedClusters[$clusterKey], [$entry]);
            continue;
        }

        $comune = $identity['comune'];
        $clusterKey = $comune !== '' ? ('COM:' . $comune) : ('REC:' . $entry['index']);
        if (!isset($missingCodeClusters[$clusterKey])) {
            $missingCodeClusters[$clusterKey] = analyticspro_make_cadastral_cluster([]);
            $missingOrder[] = $clusterKey;
        }
        analyticspro_append_entries_to_cadastral_cluster($missingCodeClusters[$clusterKey], [$entry]);
    }

    $clusters = [];
    foreach ($codedOrder as $clusterKey) {
        $clusters[] = $codedClusters[$clusterKey];
    }
    foreach ($missingOrder as $clusterKey) {
        $missingCluster = $missingCodeClusters[$clusterKey];
        $missingComune = array_key_first($missingCluster['comuni']);
        $candidateIndexes = [];
        if ($missingComune !== null) {
            foreach ($clusters as $clusterIndex => $cluster) {
                if (analyticspro_cadastral_cluster_matches_missing_code_group($cluster, (string) $missingComune)) {
                    $candidateIndexes[] = $clusterIndex;
                }
            }
        }
        if (count($candidateIndexes) === 1) {
            analyticspro_append_entries_to_cadastral_cluster($clusters[$candidateIndexes[0]], $missingCluster['entries']);
            continue;
        }
        $clusters[] = $missingCluster;
    }

    return $clusters;
}

/**
 * Symmetric canonical clustering shared by import duplicate detection, existing-property lookup
 * and duplicate-merge tooling.
 *
 * @param array<int,array<string,mixed>> $records
 * @return array<int,array<int,array<string,mixed>>>
 */
function analyticspro_group_records_by_canonical_unit(array $records, int|string|null $tenantId = null): array
{
    $buckets = [];
    $bucketOrder = [];
    foreach (array_values($records) as $index => $record) {
        $identity = analyticspro_normalize_cadastral_identity($record, $tenantId);
        $bucketKey = analyticspro_cadastral_base_bucket_key($identity);
        if (!isset($buckets[$bucketKey])) {
            $buckets[$bucketKey] = [];
            $bucketOrder[] = $bucketKey;
        }
        $buckets[$bucketKey][] = [
            'record' => $record,
            'identity' => $identity,
            'index' => (int) $index,
        ];
    }

    $result = [];
    foreach ($bucketOrder as $bucketKey) {
        $entries = $buckets[$bucketKey];
        $subalternoBuckets = [];
        $subalternoOrder = [];
        $emptySubalternoEntries = [];
        foreach ($entries as $entry) {
            $subalterno = $entry['identity']['subalterno'];
            if ($subalterno === '') {
                $emptySubalternoEntries[] = $entry;
                continue;
            }
            if (!isset($subalternoBuckets[$subalterno])) {
                $subalternoBuckets[$subalterno] = [];
                $subalternoOrder[] = $subalterno;
            }
            $subalternoBuckets[$subalterno][] = $entry;
        }

        $clusters = [];
        foreach ($subalternoOrder as $subalterno) {
            foreach (analyticspro_build_non_empty_subalterno_clusters($subalternoBuckets[$subalterno]) as $cluster) {
                $clusters[] = $cluster;
            }
        }

        $nonEmptyClusterCount = count($clusters);
        foreach ($emptySubalternoEntries as $entry) {
            $candidateIndexes = [];
            for ($clusterIndex = 0; $clusterIndex < $nonEmptyClusterCount; $clusterIndex++) {
                $cluster = $clusters[$clusterIndex];
                if (analyticspro_cadastral_cluster_matches_missing_subalterno($cluster, $entry['identity'])) {
                    $candidateIndexes[] = $clusterIndex;
                }
            }
            if (count($candidateIndexes) === 1) {
                analyticspro_append_entries_to_cadastral_cluster($clusters[$candidateIndexes[0]], [$entry]);
                continue;
            }
            $clusters[] = analyticspro_make_cadastral_cluster([$entry]);
        }

        foreach ($clusters as $cluster) {
            $result[] = array_map(static fn (array $entry): array => $entry['record'], $cluster['entries']);
        }
    }

    return $result;
}

function analyticspro_owner_cf_signature(array $owner): string
{
    return analyticspro_hash($owner['codice_fiscale'] ?? null) ?? '';
}

function analyticspro_owner_cf_hash_from_row(array $owner): string
{
    return analyticspro_hash((string) ($owner['codice_fiscale'] ?? '')) ?? '';
}

function analyticspro_owner_fallback_identity(array $owner): string
{
    $name = analyticspro_normalize_text((string) ($owner['nome'] ?? ''));
    $surname = analyticspro_normalize_text((string) ($owner['cognome'] ?? ''));
    $phone = preg_replace('/[^0-9A-Z]+/u', '', strtoupper((string) ($owner['telefono'] ?? ''))) ?? '';
    $email = preg_replace('/[^0-9A-Z@._+-]+/u', '', strtoupper((string) ($owner['email'] ?? ''))) ?? '';
    $identity = implode('|', [$surname, $name, $phone, $email]);

    return trim($identity, '|');
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
    return analyticspro_owner_cf_signature($owner);
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
        try {
            $pdo = analyticspro_db();
            if (strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'sqlite') {
                $pragma = $pdo->query('PRAGMA table_info(properties)');
                foreach ($pragma ? ($pragma->fetchAll() ?: []) : [] as $row) {
                    if (strcasecmp((string) ($row['name'] ?? ''), $column) === 0) {
                        $cache[$column] = true;
                        return true;
                    }
                }
            }
        } catch (Throwable) {
        }
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

function analyticspro_debug_sql_validation_enabled(): bool
{
    if (defined('APP_DEBUG')) {
        return (bool) APP_DEBUG;
    }

    $raw = trim((string) ($_ENV['APP_DEBUG'] ?? $_SERVER['APP_DEBUG'] ?? ''));
    return in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true);
}

/**
 * @return array<int,string>
 */
function analyticspro_sql_named_placeholders(string $sql): array
{
    preg_match_all('/:([a-z_][a-z0-9_]*)/i', $sql, $matches);
    return array_map(static fn (string $name): string => strtolower($name), $matches[1] ?? []);
}

function analyticspro_sql_concat(array $parts, ?PDO $pdo = null): string
{
    try {
        $pdo ??= analyticspro_db();
        if (strtolower((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'sqlite') {
            return implode(' || ', $parts);
        }
    } catch (Throwable) {
    }

    return 'CONCAT(' . implode(', ', $parts) . ')';
}

/**
 * @param array<string,mixed> $params
 */
function analyticspro_debug_assert_sql_params_match(string $sql, array $params): void
{
    if (!analyticspro_debug_sql_validation_enabled()) {
        return;
    }

    $placeholders = analyticspro_sql_named_placeholders($sql);
    $counts = array_count_values($placeholders);
    $duplicates = array_keys(array_filter($counts, static fn (int $count): bool => $count > 1));
    if ($duplicates !== []) {
        throw new RuntimeException('Placeholder SQL duplicato: :' . implode(', :', $duplicates));
    }

    $paramNames = [];
    foreach (array_keys($params) as $name) {
        $paramNames[] = strtolower(ltrim((string) $name, ':'));
    }
    $paramNames = array_values(array_unique($paramNames));
    $expected = array_keys($counts);
    sort($expected);
    sort($paramNames);

    $missing = array_values(array_diff($expected, $paramNames));
    $extra = array_values(array_diff($paramNames, $expected));
    if ($missing === [] && $extra === []) {
        return;
    }

    $parts = [];
    if ($missing !== []) {
        $parts[] = 'mancano :' . implode(', :', $missing);
    }
    if ($extra !== []) {
        $parts[] = 'parametri in eccesso :' . implode(', :', $extra);
    }

    throw new RuntimeException('Placeholder SQL/params non coerenti: ' . implode('; ', $parts));
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
            'titolarita' => analyticspro_extract_row_value($row, ['Titolarita', 'Titolarità']),
            'quota' => analyticspro_normalize_quota(analyticspro_extract_row_value($row, ['Quota'])),
        ],
        'note' => analyticspro_extract_row_value($row, ['Note', 'note']),
        'warnings' => $provinciaNormalized['warning'] !== '' ? [$provinciaNormalized['warning']] : [],
    ];
}

function analyticspro_import_unit_key(array $property, int $tenantId, ?int $propertyId = null, ?int $rowIndex = null, bool $singletonEmptySubalterno = true): string
{
    $identity = analyticspro_normalize_cadastral_identity($property, $tenantId);
    $subalternoKey = $identity['subalterno'];
    if ($subalternoKey === '' && $singletonEmptySubalterno) {
        $subalternoKey = 'SUB:__NONE__#' . ($propertyId !== null ? $propertyId : ('row' . (int) ($rowIndex ?? 0)));
    }

    return implode('|', [
        analyticspro_cadastral_base_bucket_key($identity),
        $subalternoKey,
        $identity['cod_catastale'] !== '' ? ('COD:' . $identity['cod_catastale']) : ('COM:' . $identity['comune']),
    ]);
}

function analyticspro_merge_import_property_values(array $current, array $incoming): array
{
    foreach ($incoming as $field => $value) {
        if (!array_key_exists($field, $current)) {
            $current[$field] = $value;
            continue;
        }
        if (is_string($value) && trim($value) !== '') {
            $current[$field] = $value;
            continue;
        }
        if (($field === 'lat' || $field === 'lng') && $value !== null) {
            $current[$field] = $value;
        }
    }

    return $current;
}

function analyticspro_merge_import_owner_values(array $current, array $incoming): array
{
    $updatable = ['tipo', 'nome', 'cognome', 'codice_fiscale', 'telefono', 'indirizzo', 'email', 'data_nascita', 'luogo_nascita', 'genere', 'titolarita', 'quota'];
    foreach ($updatable as $field) {
        if (!array_key_exists($field, $incoming)) {
            continue;
        }
        $value = $incoming[$field];
        if (is_string($value)) {
            if (trim($value) === '') {
                continue;
            }
            $current[$field] = $value;
            continue;
        }
        if ($value !== null) {
            $current[$field] = $value;
        }
    }

    return $current;
}

/**
 * Propaga il codice catastale risolto alle righe dello stesso comune/provincia che ne sono prive.
 *
 * @param array<int,array<string,mixed>> $preparedRows
 */
function analyticspro_backfill_import_cod_catastale(array &$preparedRows, int $tenantId): int
{
    $backfilled = 0;
    $identities = [];
    foreach ($preparedRows as $index => $record) {
        $identity = analyticspro_normalize_cadastral_identity($record, $tenantId);
        $identities[$index] = $identity;
    }

    foreach ($preparedRows as $preparedIndex => &$record) {
        $recordIndex = (int) ($record['__source_index'] ?? $preparedIndex);
        $identity = $identities[$recordIndex] ?? analyticspro_normalize_cadastral_identity($record, $tenantId);
        if ($identity['cod_catastale'] !== '' || $identity['provincia'] === '' || $identity['comune'] === '') {
            continue;
        }
        $candidateCodes = [];
        $candidateSubGroups = [];
        foreach ($identities as $candidateIndex => $candidateIdentity) {
            if ($candidateIndex === $recordIndex || $candidateIdentity['cod_catastale'] === '') {
                continue;
            }
            if (
                $candidateIdentity['tenant_id'] !== $identity['tenant_id']
                || $candidateIdentity['provincia'] !== $identity['provincia']
                || $candidateIdentity['sezione'] !== $identity['sezione']
                || $candidateIdentity['foglio'] !== $identity['foglio']
                || $candidateIdentity['particella'] !== $identity['particella']
                || $candidateIdentity['comune'] !== $identity['comune']
            ) {
                continue;
            }

            $sameSubalterno = $identity['subalterno'] !== '' && $candidateIdentity['subalterno'] === $identity['subalterno'];
            $uniqueMissingSubCandidate = $identity['subalterno'] === '' && $candidateIdentity['subalterno'] !== '';
            if (!$sameSubalterno && !$uniqueMissingSubCandidate) {
                continue;
            }
            $candidateCodes[$candidateIdentity['cod_catastale']] = true;
            if ($uniqueMissingSubCandidate) {
                $candidateSubGroups[$candidateIdentity['subalterno']] = true;
            }
        }

        if ($identity['subalterno'] === '' && count($candidateSubGroups) !== 1) {
            $candidateCodes = [];
        }
        $candidates = array_keys($candidateCodes);
        if (count($candidates) !== 1) {
            continue;
        }
        $resolvedCodCatastale = $candidates[0];
        $record['cod_catastale'] = $resolvedCodCatastale;
        if (isset($record['__payload']['property']) && is_array($record['__payload']['property'])) {
            $record['__payload']['property']['cod_catastale'] = $resolvedCodCatastale;
        }
        $backfilled++;
    }
    unset($record);

    return $backfilled;
}

/**
 * @return array<string,array{property:array<string,mixed>,owners:array<int,array<string,mixed>>,owner_hashes:array<int,string>,owner_keys:array<int,string>,row_indexes:array<int,int>,notes:array<int,string>,warnings:array<int,string>,source_files:array<int,string>,has_missing_cf:bool}>
 */
function analyticspro_group_import_rows_by_unit(array $rows, int $tenantId): array
{
    $prepared = analyticspro_prepare_import_groups($rows, $tenantId);
    return $prepared['groups'];
}

/**
 * @return array{groups:array<string,array{property:array<string,mixed>,owners:array<int,array<string,mixed>>,owner_hashes:array<int,string>,owner_keys:array<int,string>,row_indexes:array<int,int>,notes:array<int,string>,warnings:array<int,string>,source_files:array<int,string>,has_missing_cf:bool}>,backfilled_rows:int}
 */
function analyticspro_prepare_import_groups(array $rows, int $tenantId): array
{
    $preparedRows = [];
    foreach ($rows as $index => $row) {
        $payload = analyticspro_extract_row_payload($row, (int) $index);
        $preparedRows[] = $payload['property'] + [
            '__payload' => $payload,
            '__source_row' => $row,
            '__source_index' => (int) $index,
        ];
    }

    $backfilledRows = analyticspro_backfill_import_cod_catastale($preparedRows, $tenantId);
    $clusters = analyticspro_group_records_by_canonical_unit($preparedRows, $tenantId);
    $groups = [];
    foreach ($clusters as $groupIndex => $clusterRecords) {
        $unitKey = 'group_' . (string) $groupIndex;
        foreach ($clusterRecords as $record) {
            $payload = is_array($record['__payload'] ?? null) ? $record['__payload'] : analyticspro_extract_row_payload($record, (int) ($record['__source_index'] ?? 0));
            $property = $payload['property'];
            $owner = $payload['owner'];
            $index = (int) ($record['__source_index'] ?? 0);
            if (!isset($groups[$unitKey])) {
                $groups[$unitKey] = [
                    'property' => $property,
                    'owners' => [],
                    'owner_hashes' => [],
                    'owner_keys' => [],
                    'row_indexes' => [],
                    'notes' => [],
                    'warnings' => [],
                    'source_files' => [],
                    'has_missing_cf' => false,
                ];
            }

            $groups[$unitKey]['property'] = analyticspro_merge_import_property_values($groups[$unitKey]['property'], $property);
            $groups[$unitKey]['row_indexes'][] = $index;
            $groups[$unitKey]['warnings'] = array_values(array_unique(array_merge(
                $groups[$unitKey]['warnings'],
                array_values(array_filter(array_map(static fn ($warning): string => trim((string) $warning), $payload['warnings'] ?? []), static fn (string $warning): bool => $warning !== ''))
            )));
            $sourceFile = trim((string) (($record['__source_row']['__source_file'] ?? '') ?: ($record['__source_file'] ?? '')));
            if ($sourceFile !== '') {
                $groups[$unitKey]['source_files'][] = $sourceFile;
                $groups[$unitKey]['source_files'] = array_values(array_unique($groups[$unitKey]['source_files']));
            }
            $note = trim((string) ($payload['note'] ?? ''));
            if ($note !== '') {
                $groups[$unitKey]['notes'][] = $note;
            }
            $cfHash = analyticspro_owner_cf_hash_from_row($owner);
            $fallbackIdentity = analyticspro_owner_fallback_identity($owner);
            $ownerKey = $cfHash !== ''
                ? ('CF:' . $cfHash)
                : ('NO_CF:' . ($fallbackIdentity !== '' ? $fallbackIdentity : ('ROW_' . (string) $index)));
            $ownerIndex = array_search($ownerKey, $groups[$unitKey]['owner_keys'], true);
            if ($ownerIndex === false) {
                $groups[$unitKey]['owner_keys'][] = $ownerKey;
                $groups[$unitKey]['owners'][] = $owner;
                if ($cfHash !== '') {
                    $groups[$unitKey]['owner_hashes'][] = $cfHash;
                }
            } else {
                $groups[$unitKey]['owners'][(int) $ownerIndex] = analyticspro_merge_import_owner_values(
                    $groups[$unitKey]['owners'][(int) $ownerIndex],
                    $owner
                );
            }
            if ($cfHash === '') {
                $groups[$unitKey]['has_missing_cf'] = true;
            } else {
                $groups[$unitKey]['owner_hashes'][] = $cfHash;
                $groups[$unitKey]['owner_hashes'] = array_values(array_unique($groups[$unitKey]['owner_hashes']));
            }
        }
    }

    return [
        'groups' => $groups,
        'backfilled_rows' => $backfilledRows,
    ];
}

/**
 * @return array<string,mixed>|null
 */
function analyticspro_find_existing_property_for_import(PDO $pdo, int $tenantId, array $property): ?array
{
    $baseParams = [
        'user_id' => $tenantId,
        'provincia' => (string) ($property['provincia'] ?? ''),
    ];
    $codCatastale = trim((string) ($property['cod_catastale'] ?? ''));
    $comuneLike = strtoupper(substr(trim((string) ($property['comune'] ?? '')), 0, 6)) . '%';
    $candidates = [];
    if ($codCatastale !== '') {
        $stmt = $pdo->prepare('SELECT * FROM properties WHERE user_id = :user_id AND provincia = :provincia AND UPPER(COALESCE(cod_catastale, \'\')) = UPPER(:cod_catastale)');
        $stmt->execute($baseParams + ['cod_catastale' => $codCatastale]);
        $candidates = $stmt->fetchAll() ?: [];
    }
    if ($candidates === [] && $comuneLike !== '%') {
        $stmt = $pdo->prepare('SELECT * FROM properties WHERE user_id = :user_id AND provincia = :provincia AND UPPER(COALESCE(comune, \'\')) LIKE :comune_like');
        $stmt->execute($baseParams + ['comune_like' => $comuneLike]);
        $candidates = $stmt->fetchAll() ?: [];
    }
    if ($candidates === []) {
        return null;
    }

    $incomingRecord = $property + ['id' => '__incoming__', '__incoming__' => true];
    foreach (analyticspro_group_records_by_canonical_unit(array_merge([$incomingRecord], $candidates), $tenantId) as $cluster) {
        $containsIncoming = false;
        $clusterCandidates = [];
        foreach ($cluster as $clusterRecord) {
            if (!empty($clusterRecord['__incoming__'])) {
                $containsIncoming = true;
                continue;
            }
            $clusterCandidates[] = $clusterRecord;
        }
        if (!$containsIncoming || $clusterCandidates === []) {
            continue;
        }

        return analyticspro_choose_duplicate_property_keeper($clusterCandidates);
    }

    return null;
}

/**
 * @return array<int,array<string,mixed>>
 */
function analyticspro_find_conflicts(array $rows, int $tenantId): array
{
    $pdo = analyticspro_db();
    $findOwners = $pdo->prepare('SELECT nome_enc, cognome_enc, codice_fiscale_enc, codice_fiscale_hash FROM property_owners WHERE property_id = :property_id AND is_current = 1 ORDER BY id ASC');
    $conflicts = [];
    $grouped = analyticspro_prepare_import_groups($rows, $tenantId)['groups'];

    foreach ($grouped as $group) {
        $property = $group['property'];
        if (($property['provincia'] ?? '') === '' || ($property['comune'] ?? '') === '' || ($property['foglio'] ?? '') === '' || ($property['particella'] ?? '') === '') {
            continue;
        }

        $existing = analyticspro_find_existing_property_for_import($pdo, $tenantId, $property);
        if (!$existing) {
            continue;
        }

        $findOwners->execute(['property_id' => $existing['id']]);
        $currentOwnersRaw = $findOwners->fetchAll() ?: [];
        $currentCfHashes = [];
        $currentOwners = [];
        foreach ($currentOwnersRaw as $ownerRow) {
            $cfHash = (string) ($ownerRow['codice_fiscale_hash'] ?? '');
            if ($cfHash !== '') {
                $currentCfHashes[] = $cfHash;
            }
            $currentOwners[] = [
                'nome' => analyticspro_decrypt($ownerRow['nome_enc'] ?? null),
                'cognome' => analyticspro_decrypt($ownerRow['cognome_enc'] ?? null),
                'codice_fiscale' => analyticspro_decrypt($ownerRow['codice_fiscale_enc'] ?? null),
            ];
        }
        sort($currentCfHashes);
        $incomingCfHashes = $group['owner_hashes'];
        sort($incomingCfHashes);
        $currentHasMissingCf = count($currentOwnersRaw) > count($currentCfHashes);
        $incomingHasMissingCf = !empty($group['has_missing_cf']);
        if ($currentCfHashes === $incomingCfHashes && !$currentHasMissingCf && !$incomingHasMissingCf) {
            continue;
        }

        $incomingOwners = array_map(static fn (array $owner): array => [
            'nome' => (string) ($owner['nome'] ?? ''),
            'cognome' => (string) ($owner['cognome'] ?? ''),
            'codice_fiscale' => (string) ($owner['codice_fiscale'] ?? ''),
        ], $group['owners']);
        $rowIndexes = array_values(array_unique(array_map(static fn ($rowIndex): int => (int) $rowIndex, $group['row_indexes'])));
        sort($rowIndexes);
        $conflicts[] = [
            'decision_key' => 'property:' . (int) $existing['id'],
            'row_index' => $rowIndexes[0] ?? null,
            'row_indexes' => $rowIndexes,
            'property_id' => (int) $existing['id'],
            'comune' => (string) ($existing['comune'] ?? $property['comune'] ?? ''),
            'foglio' => (string) ($existing['foglio'] ?? $property['foglio'] ?? ''),
            'particella' => (string) ($existing['particella'] ?? $property['particella'] ?? ''),
            'subalterno' => (string) ($existing['subalterno'] ?? $property['subalterno'] ?? ''),
            'indirizzo' => (string) (($property['indirizzo'] ?? '') !== '' ? $property['indirizzo'] : ($existing['indirizzo'] ?? '')),
            'current_owners' => $currentOwners,
            'incoming_owners' => $incomingOwners,
        ];
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
    analyticspro_debug_assert_sql_params_match($sql, $params);
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
    analyticspro_debug_assert_sql_params_match($sql, $params + ['lim' => max(1, $limit)]);
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
    $sql = 'SELECT COALESCE(MAX(enrichment_attempts), 0)
         FROM properties
         WHERE ' . analyticspro_enrichment_parcel_match_where($batchId, $tenantId, $batchId === 0 && $tenantId === null);
    analyticspro_debug_assert_sql_params_match($sql, $params);
    $stmt = $pdo->prepare($sql);
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
        $sql = 'UPDATE properties SET ' . implode(', ', $sets) . '
             WHERE ' . analyticspro_enrichment_parcel_match_where($batchId, $tenantId, $batchId === 0 && $tenantId === null);
        analyticspro_debug_assert_sql_params_match($sql, $params);
        $pdo->prepare($sql)->execute($params);
    }

    return $transition['mark_unresolved'] || ($hasCoordSource && !$hasAttempts);
}

/**
 * @return array{total:int,recoverable:int,exhausted:int,unique_parcels:int,unique_parcels_recoverable:int}
 */
function analyticspro_fetch_missing_coordinate_stats(?int $tenantId = null): array
{
    return analyticspro_fetch_missing_coordinate_stats_for_pdo(analyticspro_db(), $tenantId);
}

/**
 * @return array{total:int,recoverable:int,exhausted:int,unique_parcels:int,unique_parcels_recoverable:int}
 */
function analyticspro_fetch_missing_coordinate_stats_for_pdo(PDO $pdo, ?int $tenantId = null): array
{
    $clauses = ['lat IS NULL'];
    $params = [];
    if ($tenantId !== null) {
        $clauses[] = 'user_id = :tenant_id';
        $params['tenant_id'] = $tenantId;
    }
    $where = implode(' AND ', $clauses);

    $recoverable = [];
    if (analyticspro_properties_has_coord_source_column()) {
        $recoverable[] = "(coord_source IS NULL OR coord_source <> 'unresolved')";
    }
    if (analyticspro_properties_has_enrichment_attempt_columns()) {
        $recoverable[] = 'COALESCE(enrichment_attempts, 0) < :max_attempts';
        $params['max_attempts'] = (int) ANALYTICSPRO_ENRICH_MAX_ATTEMPTS;
    }

    $sql = 'SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN ' . implode(' AND ', $recoverable !== [] ? $recoverable : ['1=1']) . ' THEN 1 ELSE 0 END) AS recoverable
         FROM properties
         WHERE ' . $where;
    analyticspro_debug_assert_sql_params_match($sql, $params);
    $stmt = $pdo->prepare($sql);
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
    $parts = [
        "COALESCE({$prefix}provincia, '')",
        "COALESCE({$prefix}comune, '')",
        "COALESCE({$prefix}cod_catastale, '')",
        "COALESCE({$prefix}sezione, '')",
        "COALESCE({$prefix}foglio, '')",
        "COALESCE({$prefix}particella, '')",
    ];
    try {
        if (strtolower((string) analyticspro_db()->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'sqlite') {
            return implode(" || '|' || ", $parts);
        }
    } catch (Throwable) {
    }

    return "CONCAT_WS('|', " . implode(', ', $parts) . ')';
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
    analyticspro_debug_assert_sql_params_match($sql, $params);
    $stmt = $pdo->prepare($sql);
    foreach ($params as $name => $value) {
        $stmt->bindValue(':' . $name, $value, PDO::PARAM_INT);
    }
    $stmt->execute();

    return (int) $stmt->fetchColumn();
}

/**
 * @return array{ok:true,scope:'tenant'|'admin',stats:array{total:int,recoverable:int,exhausted:int,unique_parcels:int,unique_parcels_recoverable:int},tenants?:array<int,array<string,mixed>>}
 */
function analyticspro_missing_coordinates_stats_payload(PDO $pdo, bool $isAdmin, ?int $tenantId): array
{
    if (!$isAdmin) {
        if ($tenantId === null) {
            throw new RuntimeException('Tenant non disponibile.');
        }

        return [
            'ok' => true,
            'scope' => 'tenant',
            'stats' => analyticspro_fetch_missing_coordinate_stats_for_pdo($pdo, $tenantId),
        ];
    }

    $overall = analyticspro_fetch_missing_coordinate_stats_for_pdo($pdo, null);
    $recoverableCondition = analyticspro_enrichment_recoverable_condition_sql('p');
    $uniqueParcelExpr = analyticspro_enrichment_unique_parcel_expr('p');
    $tenantNameExpr = 'TRIM(' . analyticspro_sql_concat(['u.nome', "' '", 'u.cognome'], $pdo) . ')';
    $detailSql = 'SELECT
            u.id AS tenant_id,
            ' . $tenantNameExpr . ' AS tenant_name,
            u.email AS tenant_email,
            COUNT(p.id) AS total,
            SUM(CASE WHEN p.id IS NOT NULL AND (' . $recoverableCondition . ') THEN 1 ELSE 0 END) AS recoverable,
            COUNT(DISTINCT CASE WHEN p.id IS NOT NULL THEN ' . $uniqueParcelExpr . ' ELSE NULL END) AS unique_parcels,
            COUNT(DISTINCT CASE WHEN p.id IS NOT NULL AND (' . $recoverableCondition . ') THEN ' . $uniqueParcelExpr . ' ELSE NULL END) AS unique_parcels_recoverable
        FROM users u
        LEFT JOIN properties p
          ON p.user_id = u.id
         AND p.lat IS NULL
        WHERE u.role = \'user\'
        GROUP BY u.id, u.nome, u.cognome, u.email
        ORDER BY total DESC, u.id ASC';
    analyticspro_debug_assert_sql_params_match($detailSql, []);
    $rows = $pdo->query($detailSql)->fetchAll() ?: [];

    $tenants = array_map(static function (array $row): array {
        $stats = analyticspro_missing_coordinate_stats_normalize(
            (int) ($row['total'] ?? 0),
            (int) ($row['recoverable'] ?? 0),
            (int) ($row['unique_parcels'] ?? 0),
            (int) ($row['unique_parcels_recoverable'] ?? 0)
        );
        return array_merge([
            'tenant_id' => (int) ($row['tenant_id'] ?? 0),
            'tenant_name' => trim((string) ($row['tenant_name'] ?? '')) !== ''
                ? trim((string) ($row['tenant_name'] ?? ''))
                : ('Tenant #' . (int) ($row['tenant_id'] ?? 0)),
            'tenant_email' => (string) ($row['tenant_email'] ?? ''),
        ], $stats);
    }, $rows);

    $tenantTotals = [
        'total' => 0,
        'recoverable' => 0,
        'unique_parcels' => 0,
        'unique_parcels_recoverable' => 0,
    ];
    foreach ($tenants as $tenant) {
        $tenantTotals['total'] += (int) ($tenant['total'] ?? 0);
        $tenantTotals['recoverable'] += (int) ($tenant['recoverable'] ?? 0);
        $tenantTotals['unique_parcels'] += (int) ($tenant['unique_parcels'] ?? 0);
        $tenantTotals['unique_parcels_recoverable'] += (int) ($tenant['unique_parcels_recoverable'] ?? 0);
    }
    $otherStats = analyticspro_missing_coordinate_stats_normalize(
        max(0, (int) ($overall['total'] ?? 0) - $tenantTotals['total']),
        max(0, (int) ($overall['recoverable'] ?? 0) - $tenantTotals['recoverable']),
        max(0, (int) ($overall['unique_parcels'] ?? 0) - $tenantTotals['unique_parcels']),
        max(0, (int) ($overall['unique_parcels_recoverable'] ?? 0) - $tenantTotals['unique_parcels_recoverable'])
    );
    if ($otherStats['total'] > 0 || $otherStats['unique_parcels'] > 0) {
        $tenants[] = array_merge([
            'tenant_id' => 0,
            'tenant_name' => 'Altri',
            'tenant_email' => '',
        ], $otherStats);
    }

    return [
        'ok' => true,
        'scope' => 'admin',
        'stats' => $overall,
        'tenants' => $tenants,
    ];
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

function analyticspro_import_decision_for_group(array $decisions, int $propertyId, array $rowIndexes): string
{
    $keys = ['property:' . $propertyId, (string) $propertyId];
    foreach ($rowIndexes as $rowIndex) {
        $keys[] = 'row:' . (int) $rowIndex;
        $keys[] = (string) (int) $rowIndex;
    }
    foreach ($keys as $key) {
        if (!array_key_exists($key, $decisions)) {
            continue;
        }
        return $decisions[$key] === 'updated' ? 'updated' : 'kept_old';
    }

    return 'kept_old';
}

function analyticspro_property_owners_has_valid_to_column(): bool
{
    static $hasColumn = null;
    if ($hasColumn !== null) {
        return $hasColumn;
    }
    try {
        $stmt = analyticspro_db()->prepare('SHOW COLUMNS FROM property_owners LIKE :column');
        $stmt->execute(['column' => 'valid_to']);
        $hasColumn = (bool) $stmt->fetch();
    } catch (Throwable) {
        $hasColumn = false;
    }

    return $hasColumn;
}

function analyticspro_property_owners_has_column(string $column): bool
{
    static $cache = [];
    if (array_key_exists($column, $cache)) {
        return $cache[$column];
    }
    try {
        $stmt = analyticspro_db()->prepare('SHOW COLUMNS FROM property_owners LIKE :column');
        $stmt->execute(['column' => $column]);
        $cache[$column] = (bool) $stmt->fetch();
    } catch (Throwable) {
        $cache[$column] = false;
    }

    return $cache[$column];
}

function analyticspro_property_owners_has_ownership_columns(): bool
{
    return analyticspro_property_owners_has_column('quota')
        && analyticspro_property_owners_has_column('titolarita');
}

function analyticspro_import_owner_display_name(array $owner): string
{
    $fullName = trim((string) (($owner['nome'] ?? '') . ' ' . ($owner['cognome'] ?? '')));
    if ($fullName === '') {
        $fullName = trim((string) (($owner['cognome'] ?? '') . ' ' . ($owner['nome'] ?? '')));
    }
    $fullName = trim($fullName);
    return $fullName !== '' ? $fullName : 'N/D';
}

function analyticspro_import_owner_note_chunk(array $owner): string
{
    $name = analyticspro_import_owner_display_name($owner);
    $cf = trim((string) ($owner['codice_fiscale'] ?? ''));

    return $name . ($cf !== '' ? (' (' . $cf . ')') : '');
}

/**
 * @return array<string,mixed>
 */
function analyticspro_build_owner_statement_params(array $owner, int $propertyId, bool $includeOwnership = false): array
{
    $params = [
        'property_id' => $propertyId,
        'tipo' => (string) ($owner['tipo'] ?? 'persona'),
        'nome_enc' => analyticspro_encrypt((string) ($owner['nome'] ?? '')),
        'cognome_enc' => analyticspro_encrypt((string) ($owner['cognome'] ?? '')),
        'codice_fiscale_enc' => analyticspro_encrypt((string) ($owner['codice_fiscale'] ?? '')),
        'telefono_enc' => analyticspro_encrypt((string) ($owner['telefono'] ?? '')),
        'indirizzo_enc' => analyticspro_encrypt((string) ($owner['indirizzo'] ?? '')),
        'email_enc' => analyticspro_encrypt((string) ($owner['email'] ?? '')),
        'nome_hash' => analyticspro_hash((string) ($owner['nome'] ?? '')),
        'cognome_hash' => analyticspro_hash((string) ($owner['cognome'] ?? '')),
        'codice_fiscale_hash' => analyticspro_hash((string) ($owner['codice_fiscale'] ?? '')),
        'telefono_hash' => analyticspro_hash((string) ($owner['telefono'] ?? '')),
        'data_nascita' => $owner['data_nascita'] ?? null,
        'luogo_nascita_enc' => analyticspro_encrypt((string) ($owner['luogo_nascita'] ?? '')),
        'genere' => $owner['genere'] ?? null,
    ];
    if ($includeOwnership) {
        $params['quota'] = trim((string) ($owner['quota'] ?? '')) !== '' ? (string) $owner['quota'] : null;
        $params['titolarita'] = trim((string) ($owner['titolarita'] ?? '')) !== '' ? (string) $owner['titolarita'] : null;
    }

    return $params;
}

function analyticspro_property_has_coordinates(array $property): bool
{
    return isset($property['lat'], $property['lng']) && is_numeric((string) $property['lat']) && is_numeric((string) $property['lng']);
}

function analyticspro_property_populated_field_count(array $property): int
{
    $count = 0;
    foreach (['indirizzo', 'categoria', 'classe', 'rendita', 'consistenza', 'piano'] as $field) {
        if (trim((string) ($property[$field] ?? '')) !== '') {
            $count++;
        }
    }

    return $count;
}

/**
 * @param array<int,array<string,mixed>> $properties
 * @return array<string,mixed>
 */
function analyticspro_choose_duplicate_property_keeper(array $properties): array
{
    usort($properties, static function (array $left, array $right): int {
        $leftVerified = !empty($left['posizione_verificata']) ? 1 : 0;
        $rightVerified = !empty($right['posizione_verificata']) ? 1 : 0;
        if ($leftVerified !== $rightVerified) {
            return $rightVerified <=> $leftVerified;
        }

        $leftCoords = analyticspro_property_has_coordinates($left) ? 1 : 0;
        $rightCoords = analyticspro_property_has_coordinates($right) ? 1 : 0;
        if ($leftCoords !== $rightCoords) {
            return $rightCoords <=> $leftCoords;
        }

        $leftFields = analyticspro_property_populated_field_count($left);
        $rightFields = analyticspro_property_populated_field_count($right);
        if ($leftFields !== $rightFields) {
            return $rightFields <=> $leftFields;
        }

        $leftCod = trim((string) ($left['cod_catastale'] ?? '')) !== '' ? 1 : 0;
        $rightCod = trim((string) ($right['cod_catastale'] ?? '')) !== '' ? 1 : 0;
        if ($leftCod !== $rightCod) {
            return $rightCod <=> $leftCod;
        }

        return ((int) ($left['id'] ?? 0)) <=> ((int) ($right['id'] ?? 0));
    });

    return $properties[0];
}

function analyticspro_duplicate_owner_merge_key(array $owner): string
{
    $cfHash = trim((string) ($owner['codice_fiscale_hash'] ?? ''));
    if ($cfHash !== '') {
        return 'CF:' . $cfHash;
    }
    $cf = trim((string) ($owner['codice_fiscale'] ?? ''));
    if ($cf !== '') {
        return 'CF:' . (analyticspro_hash($cf) ?? $cf);
    }
    $ownerId = (int) ($owner['id'] ?? 0);
    if ($ownerId > 0) {
        return 'ID:' . $ownerId;
    }

    return 'ROW:' . md5(json_encode($owner, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');
}

/**
 * @param array<int,array<string,mixed>> $cluster
 * @return array{keeper:array<string,mixed>,absorbed_ids:array<int,int>,owners:array<int,array<string,mixed>>,notes:array<int,array<string,mixed>>,assignments:array<int,array<string,mixed>>,state_note:string,summary_note:string}
 */
function analyticspro_preview_duplicate_property_merge(array $cluster): array
{
    $keeper = analyticspro_choose_duplicate_property_keeper($cluster);
    $keeperId = (int) ($keeper['id'] ?? 0);
    $orderedCluster = array_merge(
        array_values(array_filter($cluster, static fn (array $property): bool => (int) ($property['id'] ?? 0) === $keeperId)),
        array_values(array_filter($cluster, static fn (array $property): bool => (int) ($property['id'] ?? 0) !== $keeperId))
    );
    $merged = $keeper;
    foreach (['cod_catastale', 'indirizzo', 'civico', 'categoria', 'classe', 'rendita', 'consistenza', 'superficie', 'piano', 'titolarita', 'quota'] as $field) {
        if (trim((string) ($merged[$field] ?? '')) !== '') {
            continue;
        }
        foreach ($orderedCluster as $property) {
            $candidateValue = trim((string) ($property[$field] ?? ''));
            if ($candidateValue !== '') {
                $merged[$field] = $property[$field];
                break;
            }
        }
    }

    $owners = [];
    $seenCurrentByKey = [];
    foreach ($orderedCluster as $property) {
        foreach (($property['owners'] ?? []) as $owner) {
            $ownerCopy = $owner;
            $ownerCopy['property_id'] = (int) ($property['id'] ?? 0);
            $ownerCopy['quota'] = trim((string) ($ownerCopy['quota'] ?? '')) !== ''
                ? $ownerCopy['quota']
                : ($property['quota'] ?? '');
            $ownerCopy['titolarita'] = trim((string) ($ownerCopy['titolarita'] ?? '')) !== ''
                ? $ownerCopy['titolarita']
                : ($property['titolarita'] ?? '');
            $isCurrent = array_key_exists('is_current', $ownerCopy) ? (int) $ownerCopy['is_current'] : 1;
            if ($isCurrent === 1) {
                $mergeKey = analyticspro_duplicate_owner_merge_key($ownerCopy);
                if (isset($seenCurrentByKey[$mergeKey])) {
                    $ownerCopy['is_current'] = 0;
                    $ownerCopy['close_duplicate_current'] = true;
                    if (trim((string) ($ownerCopy['valid_to'] ?? '')) === '') {
                        $ownerCopy['valid_to'] = 'NOW';
                    }
                } else {
                    $seenCurrentByKey[$mergeKey] = true;
                    $ownerCopy['is_current'] = 1;
                    $ownerCopy['close_duplicate_current'] = false;
                }
            }
            $owners[] = $ownerCopy;
        }
    }

    $assignments = [];
    $seenAssignments = [];
    foreach ($orderedCluster as $property) {
        foreach (($property['assignments'] ?? []) as $assignment) {
            $subuserId = (int) ($assignment['subuser_id'] ?? 0);
            if ($subuserId <= 0 || isset($seenAssignments[$subuserId])) {
                continue;
            }
            $seenAssignments[$subuserId] = true;
            $assignments[] = $assignment;
        }
    }

    $notes = [];
    foreach ($orderedCluster as $property) {
        foreach (($property['notes'] ?? []) as $note) {
            $notes[] = $note + ['property_id' => (int) ($property['id'] ?? 0)];
        }
    }

    $states = [];
    foreach ($orderedCluster as $property) {
        $stateKey = trim((string) ($property['stato_personalizzato'] ?? ''));
        if ($stateKey === '') {
            $stateKey = trim((string) ($property['stato'] ?? ''));
        }
        if ($stateKey !== '') {
            $states[] = 'property #' . (int) ($property['id'] ?? 0) . ': ' . $stateKey;
        }
    }
    $states = array_values(array_unique($states));

    $absorbedIds = array_values(array_map(
        static fn (array $property): int => (int) $property['id'],
        array_filter($cluster, static fn (array $property): bool => (int) ($property['id'] ?? 0) !== $keeperId)
    ));

    return [
        'keeper' => $merged,
        'absorbed_ids' => $absorbedIds,
        'owners' => $owners,
        'notes' => $notes,
        'assignments' => $assignments,
        'state_note' => count($states) > 1
            ? ('Stati differenti rilevati durante unione duplicati. Mantenuto stato del record #' . $keeperId . '; altri stati: ' . implode(', ', $states) . '.')
            : '',
        'summary_note' => 'Unificati ' . count($cluster) . ' record duplicati dello stesso immobile in data ' . date('Y-m-d H:i:s'),
    ];
}

/**
 * @param array<string,array<string,mixed>> $currentByCfHash
 * @param array<string,array<string,mixed>> $incomingByCfHash
 * @return array{changed:bool,close_hashes:array<int,string>,keep_hashes:array<int,string>,insert_hashes:array<int,string>}
 */
function analyticspro_build_owner_replacement_plan(array $currentByCfHash, array $incomingByCfHash): array
{
    $currentHashes = array_keys($currentByCfHash);
    $incomingHashes = array_keys($incomingByCfHash);
    sort($currentHashes);
    sort($incomingHashes);
    $closeHashes = [];
    $keepHashes = [];
    $insertHashes = [];
    foreach ($currentHashes as $cfHash) {
        if (isset($incomingByCfHash[$cfHash])) {
            $keepHashes[] = $cfHash;
        } else {
            $closeHashes[] = $cfHash;
        }
    }
    foreach ($incomingHashes as $cfHash) {
        if (!isset($currentByCfHash[$cfHash])) {
            $insertHashes[] = $cfHash;
        }
    }

    return [
        'changed' => $currentHashes !== $incomingHashes,
        'close_hashes' => $closeHashes,
        'keep_hashes' => $keepHashes,
        'insert_hashes' => $insertHashes,
    ];
}

function analyticspro_import_owner_sets_changed(
    array $currentOwners,
    array $incomingOwners,
    array $replacementPlan,
    array $currentByCfHash,
    array $incomingByCfHash,
    array $currentNoCfKeys = [],
    array $incomingNoCfKeys = []
): bool {
    if (!empty($replacementPlan['changed'])) {
        return true;
    }

    $currentNoCf = $currentNoCfKeys;
    $incomingNoCf = $incomingNoCfKeys;
    sort($currentNoCf);
    sort($incomingNoCf);
    if ($currentNoCf !== $incomingNoCf) {
        return true;
    }
    return false;
}

/**
 * @return array{stato:null,stato_personalizzato:null,colore_marker:string}
 */
function analyticspro_import_reset_state_values(): array
{
    return [
        'stato' => null,
        'stato_personalizzato' => null,
        'colore_marker' => '#2A519F',
    ];
}

function analyticspro_process_import_batch_payload(int $batchId, array $payload): array
{
    $pdo = analyticspro_db();
    $rows = $payload['rows'] ?? [];
    $tenantId = (int) ($payload['tenant_id'] ?? 0);
    $uploaderId = (int) ($payload['uploaded_by'] ?? 0);
    $uploaderName = trim((string) ($payload['uploaded_by_name'] ?? 'Import automatico'));
    $decisions = $payload['decisions'] ?? [];
    $keepAssignmentsOnReplace = !array_key_exists('keep_assignments_on_replace', $payload)
        || (bool) $payload['keep_assignments_on_replace'];
    $preparedImport = analyticspro_prepare_import_groups($rows, $tenantId);
    $groupedRows = $preparedImport['groups'];
    $backfilledRows = (int) ($preparedImport['backfilled_rows'] ?? 0);

    $hasPianoColumn = analyticspro_properties_has_piano_column();
    $hasProvinciaOriginaleColumn = analyticspro_properties_has_provincia_originale_column();
    $hasOwnerOwnershipColumns = analyticspro_property_owners_has_ownership_columns();
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
    $selectCurrentOwners = $pdo->prepare('SELECT * FROM property_owners WHERE property_id = :property_id AND is_current = 1 ORDER BY id ASC');
    $closeOwnerById = analyticspro_property_owners_has_valid_to_column()
        ? $pdo->prepare('UPDATE property_owners SET is_current = 0, valid_to = NOW() WHERE id = :id AND is_current = 1')
        : $pdo->prepare('UPDATE property_owners SET is_current = 0 WHERE id = :id AND is_current = 1');
    $ownerInsertColumns = ['property_id', 'tipo', 'nome_enc', 'cognome_enc', 'codice_fiscale_enc', 'telefono_enc', 'indirizzo_enc', 'email_enc', 'nome_hash', 'cognome_hash', 'codice_fiscale_hash', 'telefono_hash', 'data_nascita', 'luogo_nascita_enc', 'genere'];
    $ownerInsertValues = [':property_id', ':tipo', ':nome_enc', ':cognome_enc', ':codice_fiscale_enc', ':telefono_enc', ':indirizzo_enc', ':email_enc', ':nome_hash', ':cognome_hash', ':codice_fiscale_hash', ':telefono_hash', ':data_nascita', ':luogo_nascita_enc', ':genere'];
    $ownerUpdateSet = ['tipo = :tipo', 'nome_enc = :nome_enc', 'cognome_enc = :cognome_enc', 'codice_fiscale_enc = :codice_fiscale_enc', 'telefono_enc = :telefono_enc', 'indirizzo_enc = :indirizzo_enc', 'email_enc = :email_enc', 'nome_hash = :nome_hash', 'cognome_hash = :cognome_hash', 'codice_fiscale_hash = :codice_fiscale_hash', 'telefono_hash = :telefono_hash', 'data_nascita = :data_nascita', 'luogo_nascita_enc = :luogo_nascita_enc', 'genere = :genere'];
    if ($hasOwnerOwnershipColumns) {
        $ownerInsertColumns = array_merge($ownerInsertColumns, ['quota', 'titolarita']);
        $ownerInsertValues = array_merge($ownerInsertValues, [':quota', ':titolarita']);
        $ownerUpdateSet = array_merge($ownerUpdateSet, ['quota = :quota', 'titolarita = :titolarita']);
    }
    $insertOwner = $pdo->prepare('INSERT INTO property_owners (' . implode(', ', array_merge($ownerInsertColumns, ['is_current', 'valid_from'])) . ') VALUES (' . implode(', ', array_merge($ownerInsertValues, ['1', 'NOW()'])) . ')');
    $updateCurrentOwner = $pdo->prepare('UPDATE property_owners SET ' . implode(', ', $ownerUpdateSet) . ' WHERE id = :id AND is_current = 1');
    $insertConflict = $pdo->prepare('INSERT INTO import_duplicate_conflicts (import_batch_id, property_id, action_taken, resolved_by, resolved_at) VALUES (:import_batch_id, :property_id, :action_taken, :resolved_by, NOW())');
    $insertNote = $pdo->prepare('INSERT INTO property_notes (property_id, author_id, author_name_snapshot, testo) VALUES (:property_id, :author_id, :author_name_snapshot, :testo)');
    $resetPropertyState = $pdo->prepare('UPDATE properties SET stato = NULL, stato_personalizzato = NULL, colore_marker = :colore_marker WHERE id = :id');
    $clearAssignments = $pdo->prepare('DELETE FROM property_assignments WHERE property_id = :property_id');
    $updateBatch = $pdo->prepare('UPDATE import_batches SET processed_rows = :processed_rows WHERE id = :id');

    try {
        $processed = 0;
        $savedRows = 0;
        $skippedRows = 0;
        $notesImported = 0;
        $skippedReasons = ['missing_cadastral_fields' => 0, 'unrecognized_province' => 0];
        $warnings = [];
        foreach ($groupedRows as $group) {
            $property = $group['property'];
            $rowIndexes = array_values(array_unique(array_map(static fn ($rowIndex): int => (int) $rowIndex, $group['row_indexes'] ?? [])));
            $rowCount = max(1, count($rowIndexes));
            foreach ($group['warnings'] ?? [] as $warningMessage) {
                $warningMessage = trim((string) $warningMessage);
                if ($warningMessage !== '') {
                    $warnings[] = $warningMessage;
                }
            }
            if (($property['provincia'] ?? '') === '' && trim((string) ($property['provincia_originale'] ?? '')) !== '') {
                $skippedReasons['unrecognized_province'] += $rowCount;
            }
            if (($property['provincia'] ?? '') === '' || ($property['comune'] ?? '') === '' || ($property['foglio'] ?? '') === '' || ($property['particella'] ?? '') === '') {
                $processed += $rowCount;
                $skippedRows += $rowCount;
                $skippedReasons['missing_cadastral_fields'] += $rowCount;
                $updateBatch->execute(['processed_rows' => $processed, 'id' => $batchId]);
                continue;
            }

            $existingProperty = analyticspro_find_existing_property_for_import($pdo, $tenantId, $property);
            $incomingOwners = $group['owners'] ?? [];
            $incomingByCfHash = [];
            $incomingNoCfOwnersByKey = [];
            foreach ($incomingOwners as $incomingOwner) {
                $cfHash = analyticspro_owner_cf_hash_from_row($incomingOwner);
                if ($cfHash === '') {
                    $fallbackKey = analyticspro_owner_fallback_identity($incomingOwner);
                    if ($fallbackKey === '') {
                        $fallbackKey = 'row_' . count($incomingNoCfOwnersByKey);
                    }
                    $incomingNoCfOwnersByKey[$fallbackKey] = $incomingOwner;
                    continue;
                }
                $incomingByCfHash[$cfHash] = $incomingOwner;
            }

            if ($existingProperty) {
                $propertyId = (int) $existingProperty['id'];
                $hasManualCoords = $property['lat'] !== null && $property['lng'] !== null;
                $updateParams = [
                    'import_batch_id' => $batchId,
                    'cod_catastale' => (string) ($property['cod_catastale'] ?? ''),
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

                $selectCurrentOwners->execute(['property_id' => $propertyId]);
                $currentOwners = $selectCurrentOwners->fetchAll() ?: [];
                $currentByCfHash = [];
                $currentNoCfOwnersByKey = [];
                $currentOwnerListForNote = [];
                foreach ($currentOwners as $currentOwner) {
                    $cfHash = (string) ($currentOwner['codice_fiscale_hash'] ?? '');
                    if ($cfHash !== '') {
                        $currentByCfHash[$cfHash] = $currentOwner;
                    } else {
                        $fallbackKey = analyticspro_owner_fallback_identity([
                            'nome' => analyticspro_decrypt($currentOwner['nome_enc'] ?? null),
                            'cognome' => analyticspro_decrypt($currentOwner['cognome_enc'] ?? null),
                            'telefono' => analyticspro_decrypt($currentOwner['telefono_enc'] ?? null),
                            'email' => analyticspro_decrypt($currentOwner['email_enc'] ?? null),
                        ]);
                        if ($fallbackKey === '') {
                            $fallbackKey = 'row_' . (int) ($currentOwner['id'] ?? 0);
                        }
                        $currentNoCfOwnersByKey[$fallbackKey] = $currentOwner;
                    }
                    $currentOwnerListForNote[] = [
                        'nome' => analyticspro_decrypt($currentOwner['nome_enc'] ?? null),
                        'cognome' => analyticspro_decrypt($currentOwner['cognome_enc'] ?? null),
                        'codice_fiscale' => analyticspro_decrypt($currentOwner['codice_fiscale_enc'] ?? null),
                    ];
                }

                $replacementPlan = analyticspro_build_owner_replacement_plan($currentByCfHash, $incomingByCfHash);
                $ownersChanged = analyticspro_import_owner_sets_changed(
                    $currentOwners,
                    $incomingOwners,
                    $replacementPlan,
                    $currentByCfHash,
                    $incomingByCfHash,
                    array_keys($currentNoCfOwnersByKey),
                    array_keys($incomingNoCfOwnersByKey)
                );

                if ($ownersChanged) {
                    $decision = analyticspro_import_decision_for_group($decisions, $propertyId, $rowIndexes);
                    $insertConflict->execute([
                        'import_batch_id' => $batchId,
                        'property_id' => $propertyId,
                        'action_taken' => $decision === 'updated' ? 'updated' : 'kept_old',
                        'resolved_by' => $uploaderId,
                    ]);

                    if ($decision === 'updated') {
                        $resetValues = analyticspro_import_reset_state_values();
                        foreach (($replacementPlan['close_hashes'] ?? []) as $cfHash) {
                            $currentOwner = $currentByCfHash[$cfHash] ?? null;
                            if (!is_array($currentOwner)) {
                                continue;
                            }
                            $closeOwnerById->execute(['id' => (int) $currentOwner['id']]);
                        }
                        foreach ($currentNoCfOwnersByKey as $fallbackKey => $currentOwner) {
                            if (isset($incomingNoCfOwnersByKey[$fallbackKey])) {
                                continue;
                            }
                            $closeOwnerById->execute(['id' => (int) $currentOwner['id']]);
                        }

                        foreach ($incomingByCfHash as $cfHash => $incomingOwner) {
                            if (in_array($cfHash, $replacementPlan['keep_hashes'] ?? [], true)) {
                                $existingOwner = $currentByCfHash[$cfHash];
                                $mergedOwner = analyticspro_merge_import_owner_values([
                                    'tipo' => (string) ($existingOwner['tipo'] ?? ''),
                                    'nome' => analyticspro_decrypt($existingOwner['nome_enc'] ?? null),
                                    'cognome' => analyticspro_decrypt($existingOwner['cognome_enc'] ?? null),
                                    'codice_fiscale' => analyticspro_decrypt($existingOwner['codice_fiscale_enc'] ?? null),
                                    'telefono' => analyticspro_decrypt($existingOwner['telefono_enc'] ?? null),
                                    'indirizzo' => analyticspro_decrypt($existingOwner['indirizzo_enc'] ?? null),
                                    'email' => analyticspro_decrypt($existingOwner['email_enc'] ?? null),
                                    'data_nascita' => $existingOwner['data_nascita'],
                                    'luogo_nascita' => analyticspro_decrypt($existingOwner['luogo_nascita_enc'] ?? null),
                                    'genere' => $existingOwner['genere'],
                                    'quota' => $existingOwner['quota'] ?? null,
                                    'titolarita' => $existingOwner['titolarita'] ?? null,
                                ], $incomingOwner);
                                $updateCurrentOwner->execute(['id' => (int) $existingOwner['id']] + analyticspro_build_owner_statement_params($mergedOwner, $propertyId, $hasOwnerOwnershipColumns));
                                continue;
                            }
                            if (!in_array($cfHash, $replacementPlan['insert_hashes'] ?? [], true)) {
                                continue;
                            }
                            $insertOwner->execute(analyticspro_build_owner_statement_params($incomingOwner, $propertyId, $hasOwnerOwnershipColumns));
                        }
                        foreach ($incomingNoCfOwnersByKey as $fallbackKey => $incomingOwner) {
                            if (isset($currentNoCfOwnersByKey[$fallbackKey])) {
                                $existingOwner = $currentNoCfOwnersByKey[$fallbackKey];
                                $mergedOwner = analyticspro_merge_import_owner_values([
                                    'tipo' => (string) ($existingOwner['tipo'] ?? ''),
                                    'nome' => analyticspro_decrypt($existingOwner['nome_enc'] ?? null),
                                    'cognome' => analyticspro_decrypt($existingOwner['cognome_enc'] ?? null),
                                    'codice_fiscale' => analyticspro_decrypt($existingOwner['codice_fiscale_enc'] ?? null),
                                    'telefono' => analyticspro_decrypt($existingOwner['telefono_enc'] ?? null),
                                    'indirizzo' => analyticspro_decrypt($existingOwner['indirizzo_enc'] ?? null),
                                    'email' => analyticspro_decrypt($existingOwner['email_enc'] ?? null),
                                    'data_nascita' => $existingOwner['data_nascita'],
                                    'luogo_nascita' => analyticspro_decrypt($existingOwner['luogo_nascita_enc'] ?? null),
                                    'genere' => $existingOwner['genere'],
                                    'quota' => $existingOwner['quota'] ?? null,
                                    'titolarita' => $existingOwner['titolarita'] ?? null,
                                ], $incomingOwner);
                                $updateCurrentOwner->execute(['id' => (int) $existingOwner['id']] + analyticspro_build_owner_statement_params($mergedOwner, $propertyId, $hasOwnerOwnershipColumns));
                                continue;
                            }
                            $insertOwner->execute(analyticspro_build_owner_statement_params($incomingOwner, $propertyId, $hasOwnerOwnershipColumns));
                        }

                        $resetPropertyState->execute([
                            'id' => $propertyId,
                            'colore_marker' => $resetValues['colore_marker'],
                        ]);
                        if (!$keepAssignmentsOnReplace) {
                            $clearAssignments->execute(['property_id' => $propertyId]);
                        }

                        $incomingOwnerListForNote = array_values(array_map(static fn (array $owner): array => [
                            'nome' => (string) ($owner['nome'] ?? ''),
                            'cognome' => (string) ($owner['cognome'] ?? ''),
                            'codice_fiscale' => (string) ($owner['codice_fiscale'] ?? ''),
                        ], $incomingByCfHash));
                        $insertNote->execute([
                            'property_id' => $propertyId,
                            'author_id' => $uploaderId,
                            'author_name_snapshot' => 'Sistema',
                            'testo' => 'Cambio di intestatario (import del ' . date('d/m/Y H:i') . '). Precedenti: '
                                . implode(', ', array_map(static fn (array $owner): string => analyticspro_import_owner_note_chunk($owner), $currentOwnerListForNote))
                                . ' — Nuovi: '
                                . implode(', ', array_map(static fn (array $owner): string => analyticspro_import_owner_note_chunk($owner), $incomingOwnerListForNote)),
                        ]);
                    } else {
                        foreach ($incomingByCfHash as $cfHash => $incomingOwner) {
                            if (!isset($currentByCfHash[$cfHash])) {
                                continue;
                            }
                            $existingOwner = $currentByCfHash[$cfHash];
                            $mergedOwner = analyticspro_merge_import_owner_values([
                                'tipo' => (string) ($existingOwner['tipo'] ?? ''),
                                'nome' => analyticspro_decrypt($existingOwner['nome_enc'] ?? null),
                                'cognome' => analyticspro_decrypt($existingOwner['cognome_enc'] ?? null),
                                'codice_fiscale' => analyticspro_decrypt($existingOwner['codice_fiscale_enc'] ?? null),
                                'telefono' => analyticspro_decrypt($existingOwner['telefono_enc'] ?? null),
                                'indirizzo' => analyticspro_decrypt($existingOwner['indirizzo_enc'] ?? null),
                                'email' => analyticspro_decrypt($existingOwner['email_enc'] ?? null),
                                'data_nascita' => $existingOwner['data_nascita'],
                                'luogo_nascita' => analyticspro_decrypt($existingOwner['luogo_nascita_enc'] ?? null),
                                'genere' => $existingOwner['genere'],
                                'quota' => $existingOwner['quota'] ?? null,
                                'titolarita' => $existingOwner['titolarita'] ?? null,
                            ], $incomingOwner);
                            $updateCurrentOwner->execute(['id' => (int) $existingOwner['id']] + analyticspro_build_owner_statement_params($mergedOwner, $propertyId, $hasOwnerOwnershipColumns));
                        }
                        foreach ($incomingNoCfOwnersByKey as $fallbackKey => $incomingOwner) {
                            if (!isset($currentNoCfOwnersByKey[$fallbackKey])) {
                                continue;
                            }
                            $existingOwner = $currentNoCfOwnersByKey[$fallbackKey];
                            $mergedOwner = analyticspro_merge_import_owner_values([
                                'tipo' => (string) ($existingOwner['tipo'] ?? ''),
                                'nome' => analyticspro_decrypt($existingOwner['nome_enc'] ?? null),
                                'cognome' => analyticspro_decrypt($existingOwner['cognome_enc'] ?? null),
                                'codice_fiscale' => analyticspro_decrypt($existingOwner['codice_fiscale_enc'] ?? null),
                                'telefono' => analyticspro_decrypt($existingOwner['telefono_enc'] ?? null),
                                'indirizzo' => analyticspro_decrypt($existingOwner['indirizzo_enc'] ?? null),
                                'email' => analyticspro_decrypt($existingOwner['email_enc'] ?? null),
                                'data_nascita' => $existingOwner['data_nascita'],
                                'luogo_nascita' => analyticspro_decrypt($existingOwner['luogo_nascita_enc'] ?? null),
                                'genere' => $existingOwner['genere'],
                                'quota' => $existingOwner['quota'] ?? null,
                                'titolarita' => $existingOwner['titolarita'] ?? null,
                            ], $incomingOwner);
                            $updateCurrentOwner->execute(['id' => (int) $existingOwner['id']] + analyticspro_build_owner_statement_params($mergedOwner, $propertyId, $hasOwnerOwnershipColumns));
                        }
                    }
                } elseif ($currentOwners === [] && ($incomingByCfHash !== [] || $incomingNoCfOwnersByKey !== [])) {
                    foreach (array_merge(array_values($incomingByCfHash), array_values($incomingNoCfOwnersByKey)) as $incomingOwner) {
                        $insertOwner->execute(analyticspro_build_owner_statement_params($incomingOwner, $propertyId, $hasOwnerOwnershipColumns));
                    }
                }
            } else {
                $insertParams = array_merge(analyticspro_import_reset_state_values(), [
                    'user_id' => $tenantId,
                    'import_batch_id' => $batchId,
                    'provincia' => $property['provincia'],
                    'comune' => $property['comune'],
                    'cod_catastale' => (string) ($property['cod_catastale'] ?? ''),
                    'sezione' => (string) ($property['sezione'] ?? ''),
                    'foglio' => $property['foglio'],
                    'particella' => $property['particella'],
                    'subalterno' => (string) ($property['subalterno'] ?? ''),
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
                ] + ($hasPianoColumn ? ['piano' => $property['piano'] !== '' ? $property['piano'] : null] : []));
                if ($hasProvinciaOriginaleColumn) {
                    $insertParams['provincia_originale'] = (string) ($property['provincia_originale'] ?? '');
                }
                $insertProperty->execute($insertParams);
                $propertyId = (int) $pdo->lastInsertId();
                foreach (array_merge(array_values($incomingByCfHash), array_values($incomingNoCfOwnersByKey)) as $incomingOwner) {
                    $insertOwner->execute(analyticspro_build_owner_statement_params($incomingOwner, $propertyId, $hasOwnerOwnershipColumns));
                }
                $sourceFileLabel = implode(', ', array_values(array_unique($group['source_files'] ?? [])));
                $insertNote->execute([
                    'property_id' => $propertyId,
                    'author_id' => $uploaderId,
                    'author_name_snapshot' => 'Sistema',
                    'testo' => 'Immobile importato il ' . date('d/m/Y H:i') . ($sourceFileLabel !== '' ? (' — file ' . $sourceFileLabel) : ''),
                ]);
            }

            foreach (($group['notes'] ?? []) as $note) {
                $note = trim((string) $note);
                if ($note === '') {
                    continue;
                }
                $insertNote->execute([
                    'property_id' => $propertyId,
                    'author_id' => $uploaderId,
                    'author_name_snapshot' => $uploaderName !== '' ? $uploaderName : 'Import automatico',
                    'testo' => $note,
                ]);
                $notesImported++;
            }

            $processed += $rowCount;
            $savedRows += $rowCount;
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
            'backfilled_rows' => $backfilledRows,
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
