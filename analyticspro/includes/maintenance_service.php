<?php

declare(strict_types=1);

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

function analyticspro_maintenance_fetch_columns(string $table, array $columns): array
{
    $columns = array_values(array_unique(array_values(array_filter(array_map(static fn ($column): string => trim((string) $column), $columns), static fn (string $column): bool => $column !== ''))));
    if ($columns === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $sql = "SELECT COLUMN_NAME, IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN ($placeholders)";
    $stmt = analyticspro_db()->prepare($sql);
    $stmt->execute(array_merge([$table], $columns));

    $result = [];
    foreach ($stmt->fetchAll() ?: [] as $row) {
        $result[(string) $row['COLUMN_NAME']] = [
            'COLUMN_NAME' => (string) $row['COLUMN_NAME'],
            'IS_NULLABLE' => (string) $row['IS_NULLABLE'],
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
    $number = (int) ($migration['number'] ?? 0);

    if ($number === 14) {
        $status = analyticspro_maintenance_evaluate_migration_014_status(
            analyticspro_maintenance_fetch_columns('property_owners', ['valid_to'])
        );
    } elseif ($number === 15) {
        $status = analyticspro_maintenance_evaluate_migration_015_status(
            analyticspro_maintenance_fetch_columns('property_owners', ['quota', 'titolarita'])
        );
    } elseif ($number === 16) {
        $status = analyticspro_maintenance_evaluate_migration_016_status(
            analyticspro_maintenance_fetch_columns('properties', ['cod_catastale', 'sezione', 'subalterno']),
            analyticspro_maintenance_fetch_index_rows('properties', 'uniq_estremi_catastali')
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

    foreach ($lines as $line) {
        if (preg_match('/^\s*--/', $line) === 1) {
            continue;
        }
        if (preg_match('/^\s*DELIMITER\s+(\S+)\s*$/i', $line, $matches) === 1) {
            $delimiter = (string) $matches[1];
            continue;
        }

        $buffer .= $line . "\n";
        while (($statement = analyticspro_maintenance_extract_statement_from_buffer($buffer, $delimiter)) !== null) {
            if ($statement !== '') {
                $statements[] = $statement;
            }
        }
    }

    $buffer = trim($buffer);
    if ($buffer !== '') {
        $statements[] = $buffer;
    }

    return $statements;
}

function analyticspro_maintenance_statements_are_transaction_safe(array $statements): bool
{
    foreach ($statements as $statement) {
        if (preg_match('/^\s*(ALTER|CREATE|DROP|RENAME|TRUNCATE)\b/i', (string) $statement) === 1) {
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
    } catch (Throwable $exception) {
        if ($transactionSafe && $pdo->inTransaction()) {
            $pdo->rollBack();
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
