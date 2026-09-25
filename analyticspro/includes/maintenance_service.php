<?php

declare(strict_types=1);

final class AnalyticsproMaintenanceException extends RuntimeException
{
    public function __construct(string $message, private string $errorCode, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}

function analyticspro_maintenance_access_allowed(): bool
{
    return !analyticspro_is_subuser();
}


function analyticspro_maintenance_resolve_requested_tenant_id(mixed $requestedTenantId = null): ?int
{
    $user = analyticspro_current_user();
    if (!$user) {
        throw new RuntimeException('Sessione non valida.');
    }

    if (analyticspro_is_admin()) {
        $tenantId = (int) $requestedTenantId;
        return $tenantId > 0 ? $tenantId : null;
    }

    if (analyticspro_is_subuser()) {
        throw new RuntimeException('Accesso consentito solo all\'utente principale.');
    }

    return (int) ($user['id'] ?? 0);
}

function analyticspro_maintenance_list_migrations(): array
{
    $dir = ANALYTICSPRO_ROOT . '/sql/migrations';
    $files = glob($dir . '/*.sql') ?: [];
    usort($files, static fn (string $left, string $right): int => strnatcasecmp(basename($left), basename($right)));

    $migrations = [];
    foreach ($files as $path) {
        $filename = basename($path);
        if (preg_match('/^(\d+)_/', $filename, $matches) !== 1) {
            continue;
        }
        $description = '';
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if (is_array($lines)) {
            foreach ($lines as $line) {
                if (preg_match('/^\s*--\s*(.+)$/', (string) $line, $commentMatches) === 1) {
                    $description = trim((string) $commentMatches[1]);
                    break;
                }
            }
        }
        $migrations[] = [
            'number' => (int) $matches[1],
            'filename' => $filename,
            'path' => $path,
            'description' => $description,
        ];
    }

    return $migrations;
}


function analyticspro_maintenance_migration_identifier(array $migration): string
{
    $filename = (string) ($migration['filename'] ?? '');
    return match (true) {
        str_contains($filename, '014_add_property_owners_valid_to') => 'owner_valid_to',
        str_contains($filename, '015_add_property_owner_ownership_columns') => 'owner_ownership_columns',
        str_contains($filename, '016_normalize_cadastral_nulls_and_unique') => 'normalize_cadastral_unique',
        str_contains($filename, '017_align_provincia_originale_collation') => 'provincia_originale_collation',
        default => '',
    };
}


function analyticspro_maintenance_find_migration_by_identifier(string $identifier): array
{
    foreach (analyticspro_maintenance_list_migrations() as $migration) {
        if (analyticspro_maintenance_migration_identifier($migration) === $identifier) {
            return $migration;
        }
    }

    throw new RuntimeException('Migrazione di sistema non trovata: ' . $identifier);
}

function analyticspro_maintenance_fetch_columns(string $table, array $columns): array
{
    $columns = array_values(array_unique(array_values(array_filter(array_map(static fn ($column): string => trim((string) $column), $columns), static fn (string $column): bool => $column !== ''))));
    if ($columns === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $sql = "SELECT COLUMN_NAME, IS_NULLABLE, DATA_TYPE, CHARACTER_SET_NAME, COLLATION_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN ($placeholders)";
    $stmt = analyticspro_db()->prepare($sql);
    $stmt->execute(array_merge([$table], $columns));

    $result = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $result[(string) $row['COLUMN_NAME']] = [
            'COLUMN_NAME' => (string) $row['COLUMN_NAME'],
            'IS_NULLABLE' => (string) $row['IS_NULLABLE'],
            'DATA_TYPE' => (string) ($row['DATA_TYPE'] ?? ''),
            'CHARACTER_SET_NAME' => (string) ($row['CHARACTER_SET_NAME'] ?? ''),
            'COLLATION_NAME' => (string) ($row['COLLATION_NAME'] ?? ''),
        ];
    }

    return $result;
}

function analyticspro_maintenance_fetch_index_rows(string $table, string $indexName): array
{
    $stmt = analyticspro_db()->prepare('SELECT COLUMN_NAME, SEQ_IN_INDEX, NON_UNIQUE FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND INDEX_NAME = :index_name ORDER BY SEQ_IN_INDEX ASC');
    $stmt->execute(['table_name' => $table, 'index_name' => $indexName]);
    return $stmt->fetchAll() ?: [];
}

function analyticspro_maintenance_evaluate_migration_014_status(array $columns): string
{
    return isset($columns['valid_to']) ? 'applied' : 'not_applied';
}

function analyticspro_maintenance_evaluate_migration_015_status(array $columns): string
{
    return isset($columns['quota'], $columns['titolarita']) ? 'applied' : 'not_applied';
}

function analyticspro_maintenance_evaluate_migration_016_status(array $columns, array $indexRows): string
{
    foreach (['cod_catastale', 'sezione', 'subalterno'] as $columnName) {
        if (!isset($columns[$columnName])) {
            return 'not_applied';
        }
        if (strtoupper((string) ($columns[$columnName]['IS_NULLABLE'] ?? 'YES')) !== 'NO') {
            return 'not_applied';
        }
    }

    if ($indexRows === []) {
        return 'not_applied';
    }

    $signature = implode(',', array_map(static fn (array $row): string => (string) ($row['COLUMN_NAME'] ?? ''), $indexRows));
    $allUnique = true;
    foreach ($indexRows as $row) {
        if ((int) ($row['NON_UNIQUE'] ?? 1) !== 0) {
            $allUnique = false;
            break;
        }
    }

    return $signature === 'user_id,provincia,comune,cod_catastale,sezione,foglio,particella,subalterno' && $allUnique
        ? 'applied'
        : 'not_applied';
}

function analyticspro_maintenance_evaluate_migration_017_status(array $columns): string
{
    if (!isset($columns['provincia'], $columns['provincia_originale'])) {
        return 'not_applied';
    }

    $provinciaCharset = (string) ($columns['provincia']['CHARACTER_SET_NAME'] ?? '');
    $provinciaCollation = (string) ($columns['provincia']['COLLATION_NAME'] ?? '');
    $originaleCharset = (string) ($columns['provincia_originale']['CHARACTER_SET_NAME'] ?? '');
    $originaleCollation = (string) ($columns['provincia_originale']['COLLATION_NAME'] ?? '');

    if ($provinciaCharset === '' || $provinciaCollation === '') {
        return 'unknown';
    }

    return $provinciaCharset === $originaleCharset && $provinciaCollation === $originaleCollation
        ? 'applied'
        : 'not_applied';
}

function analyticspro_maintenance_status_label(string $status): string
{
    return match ($status) {
        'applied' => 'Applicata',
        'not_applied' => 'Non applicata',
        default => 'Non determinabile',
    };
}

function analyticspro_maintenance_get_migration_status(array $migration): array
{
    $status = 'unknown';
    $identifier = analyticspro_maintenance_migration_identifier($migration);

    if ($identifier === 'owner_valid_to') {
        $status = analyticspro_maintenance_evaluate_migration_014_status(
            analyticspro_maintenance_fetch_columns('property_owners', ['valid_to'])
        );
    } elseif ($identifier === 'owner_ownership_columns') {
        $status = analyticspro_maintenance_evaluate_migration_015_status(
            analyticspro_maintenance_fetch_columns('property_owners', ['quota', 'titolarita'])
        );
    } elseif ($identifier === 'normalize_cadastral_unique') {
        $status = analyticspro_maintenance_evaluate_migration_016_status(
            analyticspro_maintenance_fetch_columns('properties', ['cod_catastale', 'sezione', 'subalterno']),
            analyticspro_maintenance_fetch_index_rows('properties', 'uniq_estremi_catastali')
        );
    } elseif ($identifier === 'provincia_originale_collation') {
        $status = analyticspro_maintenance_evaluate_migration_017_status(
            analyticspro_maintenance_fetch_columns('properties', ['provincia', 'provincia_originale'])
        );
    }

    return $migration + [
        'status' => $status,
        'status_label' => analyticspro_maintenance_status_label($status),
        'can_run' => $status !== 'applied',
    ];
}

function analyticspro_maintenance_get_migration_statuses(): array
{
    return array_map('analyticspro_maintenance_get_migration_status', analyticspro_maintenance_list_migrations());
}

function analyticspro_maintenance_find_migration(string $filename): array
{
    $filename = basename(trim($filename));
    foreach (analyticspro_maintenance_list_migrations() as $migration) {
        if (($migration['filename'] ?? '') === $filename) {
            return $migration;
        }
    }

    throw new RuntimeException('Migrazione non trovata.');
}

function analyticspro_maintenance_filter_sql_line(string $line, array &$state): string
{
    $result = '';
    $length = strlen($line);
    $inBlockComment = (bool) ($state['in_block_comment'] ?? false);
    $inSingleQuote = (bool) ($state['in_single_quote'] ?? false);
    $inDoubleQuote = (bool) ($state['in_double_quote'] ?? false);
    $inBacktick = (bool) ($state['in_backtick'] ?? false);

    for ($index = 0; $index < $length; $index++) {
        $char = $line[$index];
        $nextChar = $line[$index + 1] ?? '';

        if ($inBlockComment) {
            if ($char === '*' && $nextChar === '/') {
                $inBlockComment = false;
                $index++;
            }
            continue;
        }

        if ($inSingleQuote) {
            $result .= $char;
            if ($char === '\\') {
                if ($nextChar !== '') {
                    $result .= $nextChar;
                    $index++;
                }
                continue;
            }
            if ($char === "'" && $nextChar === "'") {
                $result .= $nextChar;
                $index++;
                continue;
            }
            if ($char === "'") {
                $inSingleQuote = false;
            }
            continue;
        }

        if ($inDoubleQuote) {
            $result .= $char;
            if ($char === '\\') {
                if ($nextChar !== '') {
                    $result .= $nextChar;
                    $index++;
                }
                continue;
            }
            if ($char === '"' && $nextChar === '"') {
                $result .= $nextChar;
                $index++;
                continue;
            }
            if ($char === '"') {
                $inDoubleQuote = false;
            }
            continue;
        }

        if ($inBacktick) {
            $result .= $char;
            if ($char === '`') {
                $inBacktick = false;
            }
            continue;
        }

        if ($char === '/' && $nextChar === '*') {
            $inBlockComment = true;
            $index++;
            continue;
        }
        $previousChar = $index > 0 ? $line[$index - 1] : '';
        if ($char === '-' && $nextChar === '-' && ($index === 0 || ctype_space($previousChar))) {
            break;
        }
        if ($char === "'") {
            $inSingleQuote = true;
            $result .= $char;
            continue;
        }
        if ($char === '"') {
            $inDoubleQuote = true;
            $result .= $char;
            continue;
        }
        if ($char === '`') {
            $inBacktick = true;
            $result .= $char;
            continue;
        }

        $result .= $char;
    }

    $state['in_block_comment'] = $inBlockComment;
    $state['in_single_quote'] = $inSingleQuote;
    $state['in_double_quote'] = $inDoubleQuote;
    $state['in_backtick'] = $inBacktick;

    return $result;
}

function analyticspro_maintenance_extract_statement_from_buffer(string &$buffer, string $delimiter): ?string
{
    $delimiterLength = strlen($delimiter);
    $length = strlen($buffer);
    $inSingleQuote = false;
    $inDoubleQuote = false;
    $inBacktick = false;

    for ($index = 0; $index < $length; $index++) {
        $char = $buffer[$index];
        $nextChar = $buffer[$index + 1] ?? '';

        if ($inSingleQuote) {
            if ($char === '\\') {
                $index++;
                continue;
            }
            if ($char === "'" && $nextChar === "'") {
                $index++;
                continue;
            }
            if ($char === "'") {
                $inSingleQuote = false;
            }
            continue;
        }

        if ($inDoubleQuote) {
            if ($char === '\\') {
                $index++;
                continue;
            }
            if ($char === '"' && $nextChar === '"') {
                $index++;
                continue;
            }
            if ($char === '"') {
                $inDoubleQuote = false;
            }
            continue;
        }

        if ($inBacktick) {
            if ($char === '`') {
                $inBacktick = false;
            }
            continue;
        }

        if ($char === "'") {
            $inSingleQuote = true;
            continue;
        }
        if ($char === '"') {
            $inDoubleQuote = true;
            continue;
        }
        if ($char === '`') {
            $inBacktick = true;
            continue;
        }

        if ($delimiterLength > 0 && substr($buffer, $index, $delimiterLength) === $delimiter) {
            $statement = trim(substr($buffer, 0, $index));
            $buffer = ltrim(substr($buffer, $index + $delimiterLength));
            return $statement;
        }
    }

    return null;
}

function analyticspro_maintenance_parse_sql_statements(string $sql): array
{
    $delimiter = ';';
    $buffer = '';
    $statements = [];
    $lines = preg_split('/\R/', $sql) ?: [];
    $state = [
        'in_block_comment' => false,
        'in_single_quote' => false,
        'in_double_quote' => false,
        'in_backtick' => false,
    ];

    foreach ($lines as $line) {
        $lineStateBefore = $state;
        $line = analyticspro_maintenance_filter_sql_line($line, $state);
        if (trim($line) === '') {
            continue;
        }
        if (empty($lineStateBefore['in_block_comment']) && empty($lineStateBefore['in_single_quote']) && empty($lineStateBefore['in_double_quote']) && empty($lineStateBefore['in_backtick']) && preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $matches) === 1) {
            while (($statement = analyticspro_maintenance_extract_statement_from_buffer($buffer, $delimiter)) !== null) {
                if ($statement !== '') {
                    $statements[] = $statement;
                }
            }
            if (trim($buffer) !== '') {
                throw new RuntimeException('Direttiva DELIMITER non valida: è presente SQL pendente prima del cambio delimitatore.');
            }
            $delimiter = (string) $matches[1];
            continue;
        }

        $buffer .= rtrim($line) . "\n";
        while (($statement = analyticspro_maintenance_extract_statement_from_buffer($buffer, $delimiter)) !== null) {
            if ($statement !== '') {
                $statements[] = $statement;
            }
        }
    }

    $buffer = trim($buffer);
    if ($buffer !== '') {
        if ($delimiter !== ';') {
            throw new RuntimeException('Blocco SQL incompleto: delimitatore custom non terminato correttamente.');
        }
        $statements[] = $buffer;
    }

    return $statements;
}

function analyticspro_maintenance_statements_are_transaction_safe(array $statements): bool
{
    foreach ($statements as $statement) {
        if (preg_match('/^\s*(ALTER|CALL|CREATE|DROP|RENAME|TRUNCATE)\b/i', (string) $statement) === 1) {
            return false;
        }
    }

    return true;
}

function analyticspro_maintenance_resolve_log_download(string $filename): string
{
    $path = analyticspro_duplicate_merge_resolve_log_path($filename);
    if (!is_file($path)) {
        throw new RuntimeException('File di log non trovato.');
    }

    return $path;
}


function analyticspro_maintenance_assert_migration_prerequisites(array $migration): void
{
    $identifier = analyticspro_maintenance_migration_identifier($migration);
    if ($identifier === 'owner_ownership_columns') {
        $migration014 = analyticspro_maintenance_get_migration_status(
            analyticspro_maintenance_find_migration_by_identifier('owner_valid_to')
        );
        if (($migration014['status'] ?? 'unknown') !== 'applied') {
            throw new AnalyticsproMaintenanceException('Prima di eseguire la migrazione 015 devi applicare la migrazione 014.', 'migration_prerequisite_missing');
        }
    }

    if ($identifier === 'normalize_cadastral_unique') {
        foreach (['owner_valid_to', 'owner_ownership_columns'] as $requiredIdentifier) {
            $requiredMigration = analyticspro_maintenance_get_migration_status(
                analyticspro_maintenance_find_migration_by_identifier($requiredIdentifier)
            );
            if (($requiredMigration['status'] ?? 'unknown') !== 'applied') {
                throw new AnalyticsproMaintenanceException('Prima di eseguire la migrazione 016 devi completare le migrazioni 014 e 015 e poi il merge duplicati.', 'migration_prerequisite_missing');
            }
        }
        if (analyticspro_duplicate_merge_count_clusters(null) > 0) {
            throw new AnalyticsproMaintenanceException('Migrazione 016 bloccata: completa prima il merge duplicati nella Sezione B e riprova.', 'migration_016_duplicates_blocked');
        }
    }
}

function analyticspro_maintenance_run_migration(string $filename): array
{
    $migration = analyticspro_maintenance_find_migration($filename);
    $statusBefore = analyticspro_maintenance_get_migration_status($migration);
    if (($statusBefore['status'] ?? '') === 'applied') {
        return [
            'migration' => $statusBefore,
            'status_before' => 'applied',
            'status_after' => 'applied',
            'executed_statements' => 0,
            'message' => 'La migrazione risulta già applicata.',
        ];
    }

    analyticspro_maintenance_assert_migration_prerequisites($migration);

    $sql = file_get_contents((string) $migration['path']);
    if (!is_string($sql) || trim($sql) === '') {
        throw new RuntimeException('File di migrazione vuoto o non leggibile.');
    }

    $statements = analyticspro_maintenance_parse_sql_statements($sql);
    if ($statements === []) {
        throw new RuntimeException('Nessuno statement SQL eseguibile trovato nel file di migrazione.');
    }

    $pdo = analyticspro_db();
    $executed = 0;
    $transactionSafe = analyticspro_maintenance_statements_are_transaction_safe($statements);

    try {
        if ($transactionSafe) {
            $pdo->beginTransaction();
        }

        foreach ($statements as $statement) {
            $pdo->exec($statement);
            $executed++;
        }

        if ($transactionSafe && $pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (AnalyticsproMaintenanceException $exception) {
        if ($transactionSafe && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    } catch (Throwable $exception) {
        if ($transactionSafe && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ((int) ($migration['number'] ?? 0) === 16 && (string) $exception->getCode() === '45000') {
            throw new AnalyticsproMaintenanceException($exception->getMessage(), 'migration_016_duplicates_blocked', $exception);
        }
        throw $exception;
    }

    $statusAfter = analyticspro_maintenance_get_migration_status($migration);

    return [
        'migration' => $statusAfter,
        'status_before' => (string) ($statusBefore['status'] ?? 'unknown'),
        'status_after' => (string) ($statusAfter['status'] ?? 'unknown'),
        'executed_statements' => $executed,
        'transaction_safe' => $transactionSafe,
        'message' => $transactionSafe
            ? 'Migrazione eseguita correttamente.'
            : 'Migrazione eseguita correttamente. Gli statement DDL/procedurali non sono transazionali e sono stati eseguiti in sequenza.',
    ];
}
