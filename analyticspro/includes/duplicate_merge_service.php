<?php

declare(strict_types=1);

function analyticspro_duplicate_merge_assert_preconditions(): void
{
    if (!analyticspro_property_owners_has_ownership_columns()) {
        throw new RuntimeException('Precondizione non soddisfatta: eseguire prima analyticspro/sql/migrations/015_add_property_owner_ownership_columns.sql.');
    }
}

function analyticspro_duplicate_merge_log_dir(): string
{
    $logDir = ANALYTICSPRO_ROOT . '/storage/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0775, true);
    }

    return $logDir;
}

function analyticspro_duplicate_merge_create_log_path(): string
{
    return analyticspro_duplicate_merge_log_dir() . '/merge_duplicate_properties_' . date('Ymd_His') . '.log';
}

function analyticspro_duplicate_merge_resolve_log_path(?string $basename = null): string
{
    if ($basename === null || trim($basename) === '') {
        return analyticspro_duplicate_merge_create_log_path();
    }

    $candidate = basename(trim($basename));
    if ($candidate === '' || preg_match('/^merge_duplicate_properties_[A-Za-z0-9._-]+\.log$/', $candidate) !== 1) {
        throw new RuntimeException('Nome file di log non valido.');
    }

    return analyticspro_duplicate_merge_log_dir() . '/' . $candidate;
}

function analyticspro_duplicate_merge_write_log(string $logPath, string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . trim($message);
    @file_put_contents($logPath, $line . PHP_EOL, FILE_APPEND);
}

/**
 * @param array<int,int> $propertyIds
 * @return array<int,array<string,mixed>>
 */
function analyticspro_duplicate_merge_fetch_records(?int $tenantId = null, array $propertyIds = [], bool $includeRelations = true): array
{
    $pdo = analyticspro_db();
    $sql = 'SELECT * FROM properties';
    $where = [];
    $params = [];

    if ($tenantId !== null && $tenantId > 0) {
        $where[] = 'user_id = :tenant_id';
        $params['tenant_id'] = $tenantId;
    }

    $propertyIds = array_values(array_unique(array_values(array_filter(array_map('intval', $propertyIds), static fn (int $id): bool => $id > 0))));
    if ($propertyIds !== []) {
        $placeholders = [];
        foreach ($propertyIds as $index => $propertyId) {
            $key = 'property_id_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $propertyId;
        }
        $where[] = 'id IN (' . implode(',', $placeholders) . ')';
    }

    if ($where !== []) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY user_id ASC, id ASC';

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $properties = $stmt->fetchAll() ?: [];
    if ($properties === [] || !$includeRelations) {
        return $properties;
    }

    $fetchedPropertyIds = array_map(static fn (array $property): int => (int) $property['id'], $properties);
    $placeholders = implode(',', array_fill(0, count($fetchedPropertyIds), '?'));

    $ownersStmt = $pdo->prepare("SELECT * FROM property_owners WHERE property_id IN ($placeholders) ORDER BY property_id ASC, is_current DESC, id ASC");
    $ownersStmt->execute($fetchedPropertyIds);
    $ownersByProperty = [];
    foreach ($ownersStmt->fetchAll() ?: [] as $owner) {
        $ownersByProperty[(int) $owner['property_id']][] = $owner;
    }

    $notesStmt = $pdo->prepare("SELECT * FROM property_notes WHERE property_id IN ($placeholders) ORDER BY created_at ASC, id ASC");
    $notesStmt->execute($fetchedPropertyIds);
    $notesByProperty = [];
    foreach ($notesStmt->fetchAll() ?: [] as $note) {
        $notesByProperty[(int) $note['property_id']][] = $note;
    }

    $assignStmt = $pdo->prepare("SELECT * FROM property_assignments WHERE property_id IN ($placeholders) ORDER BY property_id ASC, subuser_id ASC");
    $assignStmt->execute($fetchedPropertyIds);
    $assignmentsByProperty = [];
    foreach ($assignStmt->fetchAll() ?: [] as $assignment) {
        $assignmentsByProperty[(int) $assignment['property_id']][] = $assignment;
    }

    return array_map(static function (array $property) use ($ownersByProperty, $notesByProperty, $assignmentsByProperty): array {
        $propertyId = (int) $property['id'];
        $property['owners'] = $ownersByProperty[$propertyId] ?? [];
        $property['notes'] = $notesByProperty[$propertyId] ?? [];
        $property['assignments'] = $assignmentsByProperty[$propertyId] ?? [];
        return $property;
    }, $properties);
}

/**
 * @param array<int,array<string,mixed>> $records
 * @return array<int,array<int,array<string,mixed>>>
 */
function analyticspro_duplicate_merge_collect_clusters(array $records, int|string|null $tenantId = null): array
{
    return array_values(array_filter(
        analyticspro_group_records_by_canonical_unit($records, $tenantId),
        static fn (array $cluster): bool => count($cluster) > 1
    ));
}

function analyticspro_duplicate_merge_count_clusters(?int $tenantId = null): int
{
    analyticspro_duplicate_merge_assert_preconditions();
    return count(analyticspro_duplicate_merge_collect_clusters(
        analyticspro_duplicate_merge_fetch_records($tenantId, [], false),
        $tenantId
    ));
}

function analyticspro_duplicate_merge_owner_history_key(array $owner): string
{
    return implode('|', [
        analyticspro_duplicate_owner_merge_key($owner),
        (string) ((int) ($owner['is_current'] ?? 0)),
        trim((string) ($owner['valid_from'] ?? '')),
        trim((string) ($owner['valid_to'] ?? '')),
        trim((string) ($owner['quota'] ?? '')),
        trim((string) ($owner['titolarita'] ?? '')),
    ]);
}

function analyticspro_duplicate_merge_cluster_label(array $preview, array $cluster): array
{
    $reference = $preview['keeper'] ?? ($cluster[0] ?? []);
    $propertyIds = array_values(array_map(static fn (array $property): int => (int) ($property['id'] ?? 0), $cluster));
    sort($propertyIds);
    return [
        'comune' => (string) ($reference['comune'] ?? ''),
        'foglio' => (string) ($reference['foglio'] ?? ''),
        'particella' => (string) ($reference['particella'] ?? ''),
        'subalterno' => (string) ($reference['subalterno'] ?? ''),
        'keeper_id' => (int) (($preview['keeper']['id'] ?? 0)),
        'absorbed_ids' => array_values(array_map('intval', $preview['absorbed_ids'] ?? [])),
        'property_ids' => $propertyIds,
        'owners_count' => count($preview['owners'] ?? []),
        'notes_count' => count($preview['notes'] ?? []),
        'assignments_count' => count($preview['assignments'] ?? []),
        'state_note' => (string) ($preview['state_note'] ?? ''),
        'summary_note' => (string) ($preview['summary_note'] ?? ''),
    ];
}

/**
 * @param array<int,array<string,mixed>> $records
 * @return array{total_clusters:int,source_properties:int,offset:int,limit:int,has_more:bool,summary:array{clusters:int,properties:int,owners:int,notes:int,assignments:int},clusters:array<int,array{cluster:array<int,array<string,mixed>>,preview:array<string,mixed>,public:array<string,mixed>}>}
 */
function analyticspro_duplicate_merge_scan_records(array $records, int|string|null $tenantId = null, int $offset = 0, int $limit = 0): array
{
    $offset = max(0, $offset);
    $limit = max(0, $limit);
    $clusters = analyticspro_duplicate_merge_collect_clusters($records, $tenantId);
    $totalClusters = count($clusters);
    $end = $limit > 0 ? min($totalClusters, $offset + $limit) : $totalClusters;
    $summary = [
        'clusters' => $totalClusters,
        'properties' => 0,
        'owners' => 0,
        'notes' => 0,
        'assignments' => 0,
    ];
    $resultClusters = [];

    foreach ($clusters as $index => $cluster) {
        $preview = analyticspro_preview_duplicate_property_merge($cluster);
        $summary['properties'] += count($cluster);
        $summary['owners'] += count($preview['owners'] ?? []);
        $summary['notes'] += count($preview['notes'] ?? []);
        $summary['assignments'] += count($preview['assignments'] ?? []);

        if ($index < $offset || $index >= $end) {
            continue;
        }

        $resultClusters[] = [
            'cluster' => $cluster,
            'preview' => $preview,
            'public' => analyticspro_duplicate_merge_cluster_label($preview, $cluster),
        ];
    }

    return [
        'total_clusters' => $totalClusters,
        'source_properties' => count($records),
        'offset' => $offset,
        'limit' => $limit,
        'has_more' => $end < $totalClusters,
        'summary' => $summary,
        'clusters' => $resultClusters,
    ];
}

/**
 * @return array{total_clusters:int,source_properties:int,offset:int,limit:int,has_more:bool,summary:array{clusters:int,properties:int,owners:int,notes:int,assignments:int},clusters:array<int,array{cluster:array<int,array<string,mixed>>,preview:array<string,mixed>,public:array<string,mixed>}>}
 */
function analyticspro_duplicate_merge_scan(?int $tenantId = null, int $offset = 0, int $limit = 0): array
{
    analyticspro_duplicate_merge_assert_preconditions();
    return analyticspro_duplicate_merge_scan_records(
        analyticspro_duplicate_merge_fetch_records($tenantId, [], true),
        $tenantId,
        $offset,
        $limit
    );
}

/**
 * @param array<int,int> $propertyIds
 * @return array<int,array<string,mixed>>
 */
function analyticspro_duplicate_merge_resolve_cluster_by_property_ids(array $propertyIds, ?int $tenantId = null): array
{
    analyticspro_duplicate_merge_assert_preconditions();
    $propertyIds = array_values(array_unique(array_values(array_filter(array_map('intval', $propertyIds), static fn (int $id): bool => $id > 0))));
    if (count($propertyIds) < 2) {
        throw new RuntimeException('Cluster non valido.');
    }

    $records = analyticspro_duplicate_merge_fetch_records($tenantId, $propertyIds, true);
    if (count($records) !== count($propertyIds)) {
        throw new RuntimeException('Uno o più immobili del cluster non sono più disponibili.');
    }

    $clusters = analyticspro_duplicate_merge_collect_clusters($records, $tenantId);
    if (count($clusters) !== 1 || count($clusters[0]) !== count($propertyIds)) {
        throw new RuntimeException('Il cluster selezionato non è più allineato con i dati correnti. Rieseguire il dry-run.');
    }

    $resolvedIds = array_values(array_map(static fn (array $property): int => (int) ($property['id'] ?? 0), $clusters[0]));
    sort($resolvedIds);
    $requestedIds = $propertyIds;
    sort($requestedIds);
    if ($resolvedIds !== $requestedIds) {
        throw new RuntimeException('Il cluster selezionato non corrisponde più agli immobili analizzati nel dry-run. Rieseguire la scansione.');
    }

    return $clusters[0];
}

/**
 * @param array<int,array<string,mixed>> $cluster
 * @return array{keeper_id:int,absorbed_ids:array<int,int>,owners:int,notes:int,assignments:int,state_note:string,summary_note:string}
 */
function analyticspro_duplicate_merge_apply_cluster(array $cluster): array
{
    analyticspro_duplicate_merge_assert_preconditions();
    if (count($cluster) < 2) {
        throw new RuntimeException('Cluster non valido: servono almeno due immobili.');
    }

    $preview = analyticspro_preview_duplicate_property_merge($cluster);
    $keeper = $preview['keeper'];
    $keeperId = (int) ($keeper['id'] ?? 0);
    if ($keeperId <= 0) {
        throw new RuntimeException('Property da mantenere non valida.');
    }

    $pdo = analyticspro_db();
    $updateProperty = $pdo->prepare('UPDATE properties SET cod_catastale = :cod_catastale, indirizzo = :indirizzo, civico = :civico, categoria = :categoria, classe = :classe, rendita = :rendita, consistenza = :consistenza, superficie = :superficie, piano = :piano, titolarita = :titolarita, quota = :quota, lat = :lat, lng = :lng, posizione_verificata = :posizione_verificata, coord_source = :coord_source WHERE id = :id');
    $backfillOwnerOwnership = $pdo->prepare('UPDATE property_owners SET quota = COALESCE(NULLIF(quota, \'\'), :quota), titolarita = COALESCE(NULLIF(titolarita, \'\'), :titolarita) WHERE property_id = :property_id');
    $closeDuplicateOwner = $pdo->prepare('UPDATE property_owners SET is_current = 0, valid_to = COALESCE(valid_to, NOW()) WHERE id = :id AND is_current = 1');
    $moveOwnerRow = $pdo->prepare('UPDATE property_owners SET property_id = :keeper_id WHERE id = :id');
    $deleteOwnerRow = $pdo->prepare('DELETE FROM property_owners WHERE id = :id');
    $moveNotes = $pdo->prepare('UPDATE property_notes SET property_id = :keeper_id WHERE property_id = :loser_id');
    $moveHistory = $pdo->prepare('UPDATE property_status_history SET property_id = :keeper_id WHERE property_id = :loser_id');
    $moveConflicts = $pdo->prepare('UPDATE import_duplicate_conflicts SET property_id = :keeper_id WHERE property_id = :loser_id');
    $copyAssignments = $pdo->prepare('INSERT IGNORE INTO property_assignments (property_id, subuser_id, assigned_by, assigned_at) SELECT :keeper_id, subuser_id, assigned_by, assigned_at FROM property_assignments WHERE property_id = :loser_id');
    $deleteAssignments = $pdo->prepare('DELETE FROM property_assignments WHERE property_id = :loser_id');
    $deleteProperty = $pdo->prepare('DELETE FROM properties WHERE id = :id');
    $insertSystemNote = $pdo->prepare('INSERT INTO property_notes (property_id, author_id, author_name_snapshot, testo) VALUES (:property_id, :author_id, :author_name_snapshot, :testo)');
    $relationshipMoves = [
        static function (int $resolvedKeeperId, int $loserId) use ($copyAssignments, $deleteAssignments): void {
            $copyAssignments->execute(['keeper_id' => $resolvedKeeperId, 'loser_id' => $loserId]);
            $deleteAssignments->execute(['loser_id' => $loserId]);
        },
        static function (int $resolvedKeeperId, int $loserId) use ($moveNotes): void {
            $moveNotes->execute(['keeper_id' => $resolvedKeeperId, 'loser_id' => $loserId]);
        },
        static function (int $resolvedKeeperId, int $loserId) use ($moveHistory): void {
            $moveHistory->execute(['keeper_id' => $resolvedKeeperId, 'loser_id' => $loserId]);
        },
        static function (int $resolvedKeeperId, int $loserId) use ($moveConflicts): void {
            $moveConflicts->execute(['keeper_id' => $resolvedKeeperId, 'loser_id' => $loserId]);
        },
    ];

    try {
        $pdo->beginTransaction();
        foreach ($cluster as $property) {
            $backfillOwnerOwnership->execute([
                'property_id' => (int) $property['id'],
                'quota' => trim((string) ($property['quota'] ?? '')) !== '' ? (string) $property['quota'] : null,
                'titolarita' => trim((string) ($property['titolarita'] ?? '')) !== '' ? (string) $property['titolarita'] : null,
            ]);
        }

        $updateProperty->execute([
            'id' => $keeperId,
            'cod_catastale' => (string) ($keeper['cod_catastale'] ?? ''),
            'indirizzo' => trim((string) ($keeper['indirizzo'] ?? '')) !== '' ? $keeper['indirizzo'] : null,
            'civico' => trim((string) ($keeper['civico'] ?? '')) !== '' ? $keeper['civico'] : null,
            'categoria' => trim((string) ($keeper['categoria'] ?? '')) !== '' ? $keeper['categoria'] : null,
            'classe' => trim((string) ($keeper['classe'] ?? '')) !== '' ? $keeper['classe'] : null,
            'rendita' => trim((string) ($keeper['rendita'] ?? '')) !== '' ? $keeper['rendita'] : null,
            'consistenza' => trim((string) ($keeper['consistenza'] ?? '')) !== '' ? $keeper['consistenza'] : null,
            'superficie' => trim((string) ($keeper['superficie'] ?? '')) !== '' ? $keeper['superficie'] : null,
            'piano' => trim((string) ($keeper['piano'] ?? '')) !== '' ? $keeper['piano'] : null,
            'titolarita' => trim((string) ($keeper['titolarita'] ?? '')) !== '' ? $keeper['titolarita'] : null,
            'quota' => trim((string) ($keeper['quota'] ?? '')) !== '' ? $keeper['quota'] : null,
            'lat' => isset($keeper['lat']) && $keeper['lat'] !== '' ? $keeper['lat'] : null,
            'lng' => isset($keeper['lng']) && $keeper['lng'] !== '' ? $keeper['lng'] : null,
            'posizione_verificata' => !empty($keeper['posizione_verificata']) ? 1 : 0,
            'coord_source' => trim((string) ($keeper['coord_source'] ?? '')) !== '' ? $keeper['coord_source'] : null,
        ]);

        foreach ($preview['owners'] as $owner) {
            if (!empty($owner['close_duplicate_current']) && !empty($owner['id'])) {
                $closeDuplicateOwner->execute(['id' => (int) $owner['id']]);
            }
        }

        $seenOwnerHistory = [];
        foreach ($preview['owners'] as $owner) {
            $ownerId = (int) ($owner['id'] ?? 0);
            if ($ownerId <= 0) {
                continue;
            }
            $historyKey = analyticspro_duplicate_merge_owner_history_key($owner);
            if ((int) ($owner['property_id'] ?? 0) === $keeperId) {
                $seenOwnerHistory[$historyKey] = true;
                continue;
            }
            if (isset($seenOwnerHistory[$historyKey])) {
                $deleteOwnerRow->execute(['id' => $ownerId]);
                continue;
            }
            $moveOwnerRow->execute(['keeper_id' => $keeperId, 'id' => $ownerId]);
            $seenOwnerHistory[$historyKey] = true;
        }

        foreach ($preview['absorbed_ids'] as $loserId) {
            $loserId = (int) $loserId;
            foreach ($relationshipMoves as $relationshipMove) {
                $relationshipMove($keeperId, $loserId);
            }
            $deleteProperty->execute(['id' => $loserId]);
        }

        if (($preview['state_note'] ?? '') !== '') {
            $insertSystemNote->execute([
                'property_id' => $keeperId,
                'author_id' => (int) ($keeper['user_id'] ?? 0),
                'author_name_snapshot' => 'Sistema',
                'testo' => (string) $preview['state_note'],
            ]);
        }
        $insertSystemNote->execute([
            'property_id' => $keeperId,
            'author_id' => (int) ($keeper['user_id'] ?? 0),
            'author_name_snapshot' => 'Sistema',
            'testo' => (string) ($preview['summary_note'] ?? ''),
        ]);

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    return [
        'keeper_id' => $keeperId,
        'absorbed_ids' => array_values(array_map('intval', $preview['absorbed_ids'] ?? [])),
        'owners' => count($preview['owners'] ?? []),
        'notes' => count($preview['notes'] ?? []),
        'assignments' => count($preview['assignments'] ?? []),
        'state_note' => (string) ($preview['state_note'] ?? ''),
        'summary_note' => (string) ($preview['summary_note'] ?? ''),
    ];
}
