<?php

declare(strict_types=1);

if (!defined('ANALYTICSPRO_ROOT')) {
    define('ANALYTICSPRO_ROOT', dirname(__DIR__));
}

if (!function_exists('analyticspro_db')) {
    function analyticspro_db(): MergeApplyPdoStub
    {
        global $analyticsproMergeStub;
        return $analyticsproMergeStub;
    }
}

require_once ANALYTICSPRO_ROOT . '/includes/importer.php';
require_once ANALYTICSPRO_ROOT . '/includes/duplicate_merge_service.php';

if (!function_exists('analyticspro_hash')) {
    function analyticspro_hash(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return hash('sha256', $value);
    }
}

final class MergeApplyPdoStub
{
    public array $tables;
    public ?int $failOnDeletePropertyId = null;
    public bool $transactionStarted = false;
    public bool $transactionCommitted = false;
    public bool $transactionRolledBack = false;
    private bool $inTransaction = false;
    private array $snapshot = [];

    public function __construct(array $tables)
    {
        $this->tables = $tables;
    }

    public function prepare(string $sql): MergeApplyStatementStub
    {
        return new MergeApplyStatementStub($this, $sql);
    }

    public function beginTransaction(): bool
    {
        $this->transactionStarted = true;
        $this->inTransaction = true;
        $this->snapshot = unserialize(serialize($this->tables));
        return true;
    }

    public function commit(): bool
    {
        $this->transactionCommitted = true;
        $this->inTransaction = false;
        $this->snapshot = [];
        return true;
    }

    public function rollBack(): bool
    {
        $this->transactionRolledBack = true;
        $this->tables = unserialize(serialize($this->snapshot));
        $this->inTransaction = false;
        $this->snapshot = [];
        return true;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function nextNoteId(): int
    {
        $ids = array_map(static fn (array $row): int => (int) ($row['id'] ?? 0), $this->tables['property_notes']);
        return ($ids ? max($ids) : 0) + 1;
    }
}

final class MergeApplyStatementStub
{
    private array $result = [];

    public function __construct(private MergeApplyPdoStub $pdo, private string $sql)
    {
    }

    public function execute(array $params = []): bool
    {
        $sql = $this->sql;
        $this->result = [];

        if (str_starts_with($sql, 'SHOW COLUMNS FROM property_owners LIKE')) {
            $column = (string) ($params['column'] ?? '');
            if (in_array($column, ['quota', 'titolarita'], true)) {
                $this->result = [['Field' => $column]];
            }
            return true;
        }

        if (str_starts_with($sql, 'UPDATE property_owners SET quota = COALESCE')) {
            foreach ($this->pdo->tables['property_owners'] as &$owner) {
                if ((int) $owner['property_id'] !== (int) $params['property_id']) {
                    continue;
                }
                if (trim((string) ($owner['quota'] ?? '')) === '' && $params['quota'] !== null) {
                    $owner['quota'] = $params['quota'];
                }
                if (trim((string) ($owner['titolarita'] ?? '')) === '' && $params['titolarita'] !== null) {
                    $owner['titolarita'] = $params['titolarita'];
                }
            }
            unset($owner);
            return true;
        }

        if (str_starts_with($sql, 'UPDATE properties SET')) {
            foreach ($this->pdo->tables['properties'] as &$property) {
                if ((int) $property['id'] !== (int) $params['id']) {
                    continue;
                }
                foreach ($params as $key => $value) {
                    if ($key !== 'id') {
                        $property[$key] = $value;
                    }
                }
            }
            unset($property);
            return true;
        }

        if (str_starts_with($sql, 'UPDATE property_owners SET is_current = 0')) {
            foreach ($this->pdo->tables['property_owners'] as &$owner) {
                if ((int) $owner['id'] === (int) $params['id'] && (int) ($owner['is_current'] ?? 0) === 1) {
                    $owner['is_current'] = 0;
                    if (trim((string) ($owner['valid_to'] ?? '')) === '') {
                        $owner['valid_to'] = 'NOW';
                    }
                }
            }
            unset($owner);
            return true;
        }

        if (str_starts_with($sql, 'UPDATE property_owners SET property_id =')) {
            foreach ($this->pdo->tables['property_owners'] as &$owner) {
                if ((int) $owner['id'] === (int) $params['id']) {
                    $owner['property_id'] = (int) $params['keeper_id'];
                }
            }
            unset($owner);
            return true;
        }

        if (str_starts_with($sql, 'DELETE FROM property_owners WHERE id =')) {
            $this->pdo->tables['property_owners'] = array_values(array_filter(
                $this->pdo->tables['property_owners'],
                static fn (array $owner): bool => (int) $owner['id'] !== (int) $params['id']
            ));
            return true;
        }

        if (str_starts_with($sql, 'UPDATE property_notes SET property_id =')) {
            foreach ($this->pdo->tables['property_notes'] as &$note) {
                if ((int) $note['property_id'] === (int) $params['loser_id']) {
                    $note['property_id'] = (int) $params['keeper_id'];
                }
            }
            unset($note);
            return true;
        }

        if (str_starts_with($sql, 'UPDATE property_status_history SET property_id =')) {
            foreach ($this->pdo->tables['property_status_history'] as &$row) {
                if ((int) $row['property_id'] === (int) $params['loser_id']) {
                    $row['property_id'] = (int) $params['keeper_id'];
                }
            }
            unset($row);
            return true;
        }

        if (str_starts_with($sql, 'UPDATE import_duplicate_conflicts SET property_id =')) {
            foreach ($this->pdo->tables['import_duplicate_conflicts'] as &$row) {
                if ((int) $row['property_id'] === (int) $params['loser_id']) {
                    $row['property_id'] = (int) $params['keeper_id'];
                }
            }
            unset($row);
            return true;
        }

        if (str_starts_with($sql, 'INSERT IGNORE INTO property_assignments')) {
            $existing = [];
            foreach ($this->pdo->tables['property_assignments'] as $assignment) {
                $existing[(int) $assignment['property_id'] . ':' . (int) $assignment['subuser_id']] = true;
            }
            foreach ($this->pdo->tables['property_assignments'] as $assignment) {
                if ((int) $assignment['property_id'] !== (int) $params['loser_id']) {
                    continue;
                }
                $key = (int) $params['keeper_id'] . ':' . (int) $assignment['subuser_id'];
                if (isset($existing[$key])) {
                    continue;
                }
                $copy = $assignment;
                $copy['property_id'] = (int) $params['keeper_id'];
                $existing[$key] = true;
                $this->pdo->tables['property_assignments'][] = $copy;
            }
            return true;
        }

        if (str_starts_with($sql, 'DELETE FROM property_assignments WHERE property_id =')) {
            $this->pdo->tables['property_assignments'] = array_values(array_filter(
                $this->pdo->tables['property_assignments'],
                static fn (array $assignment): bool => (int) $assignment['property_id'] !== (int) $params['loser_id']
            ));
            return true;
        }

        if (str_starts_with($sql, 'DELETE FROM properties WHERE id =')) {
            if ($this->pdo->failOnDeletePropertyId !== null && (int) $params['id'] === $this->pdo->failOnDeletePropertyId) {
                throw new RuntimeException('Simulated delete failure');
            }
            $this->pdo->tables['properties'] = array_values(array_filter(
                $this->pdo->tables['properties'],
                static fn (array $property): bool => (int) $property['id'] !== (int) $params['id']
            ));
            return true;
        }

        if (str_starts_with($sql, 'INSERT INTO property_notes')) {
            $this->pdo->tables['property_notes'][] = [
                'id' => $this->pdo->nextNoteId(),
                'property_id' => (int) $params['property_id'],
                'author_id' => (int) $params['author_id'],
                'author_name_snapshot' => (string) $params['author_name_snapshot'],
                'testo' => (string) $params['testo'],
            ];
            return true;
        }

        throw new RuntimeException('SQL non gestito nello stub: ' . $sql);
    }

    public function fetch(): mixed
    {
        return array_shift($this->result);
    }
}

$records = [
    [
        'id' => 10,
        'user_id' => 77,
        'provincia' => 'MI',
        'comune' => 'Milano',
        'cod_catastale' => '',
        'sezione' => '',
        'foglio' => '10',
        'particella' => '123',
        'subalterno' => '1',
        'owners' => [[
            'id' => 100,
            'property_id' => 10,
            'codice_fiscale_hash' => analyticspro_hash('AAA111'),
            'codice_fiscale' => 'AAA111',
            'quota' => '',
            'titolarita' => '',
            'is_current' => 1,
            'valid_to' => null,
        ]],
        'notes' => [['id' => 1, 'property_id' => 10, 'testo' => 'nota 1']],
        'assignments' => [['property_id' => 10, 'subuser_id' => 1, 'assigned_by' => 77, 'assigned_at' => '2026-01-01 10:00:00']],
        'quota' => '1/2',
        'titolarita' => 'Piena proprietà',
        'stato' => 'interessato',
    ],
    [
        'id' => 11,
        'user_id' => 77,
        'provincia' => 'MI',
        'comune' => 'Milano',
        'cod_catastale' => 'F205',
        'sezione' => '',
        'foglio' => '010',
        'particella' => '0123',
        'subalterno' => '0001',
        'indirizzo' => 'Via Roma',
        'owners' => [[
            'id' => 101,
            'property_id' => 11,
            'codice_fiscale_hash' => analyticspro_hash('BBB222'),
            'codice_fiscale' => 'BBB222',
            'quota' => '1/4',
            'titolarita' => 'Usufrutto',
            'is_current' => 1,
            'valid_to' => null,
        ]],
        'notes' => [['id' => 2, 'property_id' => 11, 'testo' => 'nota 2']],
        'assignments' => [['property_id' => 11, 'subuser_id' => 2, 'assigned_by' => 77, 'assigned_at' => '2026-01-01 10:05:00']],
        'quota' => '1/4',
        'titolarita' => 'Usufrutto',
        'stato' => 'interessato',
        'posizione_verificata' => 1,
        'lat' => 45.1,
        'lng' => 9.1,
    ],
];

$scan = analyticspro_duplicate_merge_scan_records($records, 77, 0, 0);
$preview = analyticspro_preview_duplicate_property_merge($records);
$pass = true;
$errors = [];

if (($scan['total_clusters'] ?? 0) !== 1 || count($scan['clusters'] ?? []) !== 1) {
    $pass = false;
    $errors[] = 'La scansione condivisa deve restituire un unico cluster duplicato.';
} else {
    $servicePreview = $scan['clusters'][0]['preview'] ?? [];
    $public = $scan['clusters'][0]['public'] ?? [];
    if (($servicePreview['keeper']['id'] ?? 0) !== ($preview['keeper']['id'] ?? 0)) {
        $pass = false;
        $errors[] = 'CLI e percorso web devono scegliere lo stesso keeper.';
    }
    if (($servicePreview['absorbed_ids'] ?? []) !== ($preview['absorbed_ids'] ?? [])) {
        $pass = false;
        $errors[] = 'CLI e percorso web devono assorbire gli stessi record duplicati.';
    }
    if (($public['property_ids'] ?? []) !== [10, 11]) {
        $pass = false;
        $errors[] = 'Il payload pubblico del cluster deve preservare gli id property per l’apply batch web.';
    }
    if (($public['owners_count'] ?? 0) !== count($preview['owners'] ?? [])) {
        $pass = false;
        $errors[] = 'Il riepilogo pubblico del cluster deve riflettere lo stesso numero di owners del preview condiviso.';
    }
}

$initialTables = [
    'properties' => [
        ['id' => 10, 'user_id' => 77, 'cod_catastale' => '', 'indirizzo' => null, 'civico' => null, 'categoria' => null, 'classe' => null, 'rendita' => null, 'consistenza' => null, 'superficie' => null, 'piano' => null, 'titolarita' => 'Piena proprietà', 'quota' => '1/2', 'lat' => null, 'lng' => null, 'posizione_verificata' => 0, 'coord_source' => null],
        ['id' => 11, 'user_id' => 77, 'cod_catastale' => 'F205', 'indirizzo' => 'Via Roma', 'civico' => null, 'categoria' => null, 'classe' => null, 'rendita' => null, 'consistenza' => null, 'superficie' => null, 'piano' => null, 'titolarita' => 'Usufrutto', 'quota' => '1/4', 'lat' => 45.1, 'lng' => 9.1, 'posizione_verificata' => 1, 'coord_source' => 'manual'],
    ],
    'property_owners' => [
        ['id' => 100, 'property_id' => 10, 'codice_fiscale_hash' => analyticspro_hash('AAA111'), 'quota' => '', 'titolarita' => '', 'is_current' => 1, 'valid_to' => null],
        ['id' => 101, 'property_id' => 11, 'codice_fiscale_hash' => analyticspro_hash('BBB222'), 'quota' => '1/4', 'titolarita' => 'Usufrutto', 'is_current' => 1, 'valid_to' => null],
    ],
    'property_notes' => [
        ['id' => 1, 'property_id' => 10, 'author_id' => 77, 'author_name_snapshot' => 'Utente', 'testo' => 'nota 1'],
        ['id' => 2, 'property_id' => 11, 'author_id' => 77, 'author_name_snapshot' => 'Utente', 'testo' => 'nota 2'],
    ],
    'property_assignments' => [
        ['id' => 1, 'property_id' => 10, 'subuser_id' => 1, 'assigned_by' => 77, 'assigned_at' => '2026-01-01 10:00:00'],
        ['id' => 2, 'property_id' => 11, 'subuser_id' => 2, 'assigned_by' => 77, 'assigned_at' => '2026-01-01 10:05:00'],
    ],
    'property_status_history' => [['id' => 1, 'property_id' => 10]],
    'import_duplicate_conflicts' => [['id' => 1, 'property_id' => 10]],
];

global $analyticsproMergeStub;
$analyticsproMergeStub = new MergeApplyPdoStub($initialTables);
$result = analyticspro_duplicate_merge_apply_cluster($records);
if (($result['keeper_id'] ?? 0) !== 11 || ($result['absorbed_ids'] ?? []) !== [10]) {
    $pass = false;
    $errors[] = 'L’apply condiviso deve mantenere il keeper atteso e assorbire il duplicato corretto.';
}
if (!$analyticsproMergeStub->transactionStarted || !$analyticsproMergeStub->transactionCommitted || $analyticsproMergeStub->transactionRolledBack) {
    $pass = false;
    $errors[] = 'L’apply condiviso deve usare una transazione e committare in caso di successo.';
}
if (count($analyticsproMergeStub->tables['properties']) !== 1 || (int) $analyticsproMergeStub->tables['properties'][0]['id'] !== 11) {
    $pass = false;
    $errors[] = 'Dopo l’apply deve restare una sola property keeper.';
}
$ownerPropertyIds = array_values(array_unique(array_map(static fn (array $owner): int => (int) $owner['property_id'], $analyticsproMergeStub->tables['property_owners'])));
if ($ownerPropertyIds !== [11]) {
    $pass = false;
    $errors[] = 'Dopo l’apply tutti gli owner devono puntare alla property keeper.';
}
if (count($analyticsproMergeStub->tables['property_assignments']) !== 2) {
    $pass = false;
    $errors[] = 'Le assegnazioni del duplicato devono essere mantenute sul keeper.';
}
$systemNotes = array_values(array_filter(
    $analyticsproMergeStub->tables['property_notes'],
    static fn (array $note): bool => (string) ($note['author_name_snapshot'] ?? '') === 'Sistema'
));
if (count($analyticsproMergeStub->tables['property_notes']) !== 4 || count($systemNotes) !== 2) {
    $pass = false;
    $errors[] = 'L’apply deve spostare le note esistenti e aggiungere le note di sistema previste dal merge.';
}

$analyticsproMergeStub = new MergeApplyPdoStub($initialTables);
$analyticsproMergeStub->failOnDeletePropertyId = 10;
$beforeRollback = serialize($analyticsproMergeStub->tables);
$rollbackTriggered = false;
try {
    analyticspro_duplicate_merge_apply_cluster($records);
} catch (Throwable $exception) {
    $rollbackTriggered = $exception->getMessage() === 'Simulated delete failure';
}
if (!$rollbackTriggered) {
    $pass = false;
    $errors[] = 'Lo stub di errore deve propagare l’eccezione simulata durante l’apply.';
}
if (!$analyticsproMergeStub->transactionRolledBack || serialize($analyticsproMergeStub->tables) !== $beforeRollback) {
    $pass = false;
    $errors[] = 'In caso di errore l’apply condiviso deve fare rollback completo della transazione.';
}

if ($pass) {
    echo "PASS: parity merge service CLI/web OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: {$error}\n";
}
exit(1);
