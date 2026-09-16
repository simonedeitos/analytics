<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Questo script è utilizzabile solo da CLI.\n");
    exit(1);
}

require dirname(__DIR__) . '/includes/bootstrap.php';

$options = getopt('', ['tenant::', 'apply', 'dry-run']);
$tenantFilter = isset($options['tenant']) ? (int) $options['tenant'] : null;
$apply = array_key_exists('apply', $options);
$dryRun = !$apply || array_key_exists('dry-run', $options);

$logDir = ANALYTICSPRO_ROOT . '/storage/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0775, true);
}
$logPath = $logDir . '/merge_duplicate_properties_' . date('Ymd_His') . '.log';

$log = static function (string $message) use ($logPath): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
    echo $line . PHP_EOL;
    @file_put_contents($logPath, $line . PHP_EOL, FILE_APPEND);
};

$pdo = analyticspro_db();
$propertySql = 'SELECT * FROM properties';
$params = [];
if ($tenantFilter !== null && $tenantFilter > 0) {
    $propertySql .= ' WHERE user_id = :tenant_id';
    $params['tenant_id'] = $tenantFilter;
}
$propertySql .= ' ORDER BY user_id ASC, id ASC';
$propertyStmt = $pdo->prepare($propertySql);
$propertyStmt->execute($params);
$properties = $propertyStmt->fetchAll() ?: [];

if ($properties === []) {
    $log('Nessuna property trovata per il filtro richiesto. Log: ' . $logPath);
    exit(0);
}

$propertyIds = array_map(static fn (array $property): int => (int) $property['id'], $properties);
$placeholders = implode(',', array_fill(0, count($propertyIds), '?'));

$ownersStmt = $pdo->prepare("SELECT * FROM property_owners WHERE property_id IN ($placeholders) ORDER BY property_id ASC, is_current DESC, id ASC");
$ownersStmt->execute($propertyIds);
$ownersByProperty = [];
foreach ($ownersStmt->fetchAll() ?: [] as $owner) {
    $ownersByProperty[(int) $owner['property_id']][] = $owner;
}

$notesStmt = $pdo->prepare("SELECT * FROM property_notes WHERE property_id IN ($placeholders) ORDER BY created_at ASC, id ASC");
$notesStmt->execute($propertyIds);
$notesByProperty = [];
foreach ($notesStmt->fetchAll() ?: [] as $note) {
    $notesByProperty[(int) $note['property_id']][] = $note;
}

$assignStmt = $pdo->prepare("SELECT * FROM property_assignments WHERE property_id IN ($placeholders) ORDER BY property_id ASC, subuser_id ASC");
$assignStmt->execute($propertyIds);
$assignmentsByProperty = [];
foreach ($assignStmt->fetchAll() ?: [] as $assignment) {
    $assignmentsByProperty[(int) $assignment['property_id']][] = $assignment;
}

$clusterInput = array_map(static function (array $property) use ($ownersByProperty, $notesByProperty, $assignmentsByProperty): array {
    $propertyId = (int) $property['id'];
    $property['owners'] = $ownersByProperty[$propertyId] ?? [];
    $property['notes'] = $notesByProperty[$propertyId] ?? [];
    $property['assignments'] = $assignmentsByProperty[$propertyId] ?? [];
    return $property;
}, $properties);

$clusters = array_values(array_filter(
    analyticspro_group_records_by_canonical_unit($clusterInput),
    static fn (array $cluster): bool => count($cluster) > 1
));

$summary = [
    'clusters' => count($clusters),
    'properties' => 0,
    'owners' => 0,
    'notes' => 0,
    'assignments' => 0,
];

$log('Modalità: ' . ($dryRun ? 'DRY-RUN' : 'APPLY') . ' — tenant filter: ' . ($tenantFilter !== null && $tenantFilter > 0 ? (string) $tenantFilter : 'tutti') . '. Log: ' . $logPath);
$log('Cluster duplicati rilevati: ' . count($clusters));

$updateProperty = $pdo->prepare('UPDATE properties SET cod_catastale = :cod_catastale, indirizzo = :indirizzo, civico = :civico, categoria = :categoria, classe = :classe, rendita = :rendita, consistenza = :consistenza, superficie = :superficie, piano = :piano, titolarita = :titolarita, quota = :quota WHERE id = :id');
$backfillOwnerOwnership = $pdo->prepare('UPDATE property_owners SET quota = COALESCE(NULLIF(quota, \'\'), :quota), titolarita = COALESCE(NULLIF(titolarita, \'\'), :titolarita) WHERE property_id = :property_id');
$closeDuplicateOwner = $pdo->prepare('UPDATE property_owners SET is_current = 0, valid_to = COALESCE(valid_to, NOW()) WHERE id = :id AND is_current = 1');
$moveOwners = $pdo->prepare('UPDATE property_owners SET property_id = :keeper_id WHERE property_id = :loser_id');
$moveNotes = $pdo->prepare('UPDATE property_notes SET property_id = :keeper_id WHERE property_id = :loser_id');
$moveHistory = $pdo->prepare('UPDATE property_status_history SET property_id = :keeper_id WHERE property_id = :loser_id');
$moveConflicts = $pdo->prepare('UPDATE import_duplicate_conflicts SET property_id = :keeper_id WHERE property_id = :loser_id');
$copyAssignments = $pdo->prepare('INSERT IGNORE INTO property_assignments (property_id, subuser_id, assigned_by, assigned_at) SELECT :keeper_id, subuser_id, assigned_by, assigned_at FROM property_assignments WHERE property_id = :loser_id');
$deleteProperty = $pdo->prepare('DELETE FROM properties WHERE id = :id');
$insertSystemNote = $pdo->prepare('INSERT INTO property_notes (property_id, author_id, author_name_snapshot, testo) VALUES (:property_id, :author_id, :author_name_snapshot, :testo)');

foreach ($clusters as $clusterIndex => $cluster) {
    $preview = analyticspro_preview_duplicate_property_merge($cluster);
    $keeper = $preview['keeper'];
    $keeperId = (int) ($keeper['id'] ?? 0);
    $absorbedIds = $preview['absorbed_ids'];
    $summary['properties'] += count($cluster);
    $summary['owners'] += count($preview['owners']);
    $summary['notes'] += count($preview['notes']);
    $summary['assignments'] += count($preview['assignments']);

    $log(sprintf(
        'Cluster %d: keeper #%d, assorbiti [%s], owners=%d, notes=%d, assignments=%d',
        $clusterIndex + 1,
        $keeperId,
        implode(', ', $absorbedIds),
        count($preview['owners']),
        count($preview['notes']),
        count($preview['assignments'])
    ));

    if ($dryRun) {
        continue;
    }

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
        ]);

        foreach ($preview['owners'] as $owner) {
            if (!empty($owner['close_duplicate_current']) && !empty($owner['id'])) {
                $closeDuplicateOwner->execute(['id' => (int) $owner['id']]);
            }
        }

        foreach ($absorbedIds as $loserId) {
            $copyAssignments->execute(['keeper_id' => $keeperId, 'loser_id' => $loserId]);
            $moveOwners->execute(['keeper_id' => $keeperId, 'loser_id' => $loserId]);
            $moveNotes->execute(['keeper_id' => $keeperId, 'loser_id' => $loserId]);
            $moveHistory->execute(['keeper_id' => $keeperId, 'loser_id' => $loserId]);
            $moveConflicts->execute(['keeper_id' => $keeperId, 'loser_id' => $loserId]);
            $deleteProperty->execute(['id' => $loserId]);
        }

        if ($preview['state_note'] !== '') {
            $insertSystemNote->execute([
                'property_id' => $keeperId,
                'author_id' => (int) ($keeper['user_id'] ?? 0),
                'author_name_snapshot' => 'Sistema',
                'testo' => $preview['state_note'],
            ]);
        }
        $insertSystemNote->execute([
            'property_id' => $keeperId,
            'author_id' => (int) ($keeper['user_id'] ?? 0),
            'author_name_snapshot' => 'Sistema',
            'testo' => $preview['summary_note'],
        ]);

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $log('ERRORE cluster #' . ($clusterIndex + 1) . ': ' . $exception->getMessage());
        exit(1);
    }
}

$log(sprintf(
    'Report finale: clusters=%d, properties coinvolte=%d, owners coinvolti=%d, notes coinvolte=%d, assignments coinvolte=%d',
    $summary['clusters'],
    $summary['properties'],
    $summary['owners'],
    $summary['notes'],
    $summary['assignments']
));

exit(0);
