<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/api_bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/property_repository.php';

analyticspro_api_guard();
analyticspro_api_require_auth();

try {
    if (!analyticspro_is_main_user()) {
        throw new RuntimeException('Solo l\'utente principale può eliminare immobili singoli.');
    }

    $input = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    analyticspro_verify_csrf($input['csrf_token'] ?? null);

    $propertyId = (int) ($input['property_id'] ?? 0);
    $propertyIds = [];
    if (is_array($input['property_ids'] ?? null)) {
        foreach ($input['property_ids'] as $candidateId) {
            $candidateId = (int) $candidateId;
            if ($candidateId > 0 && !in_array($candidateId, $propertyIds, true)) {
                $propertyIds[] = $candidateId;
            }
        }
    }
    if ($propertyId > 0 && $propertyIds === []) {
        $propertyIds[] = $propertyId;
    }
    if ($propertyIds === []) {
        throw new RuntimeException('Immobile non valido.');
    }

    $tenantId = analyticspro_current_tenant_id();
    if ($tenantId === null) {
        throw new RuntimeException('Tenant non disponibile.');
    }

    $placeholders = implode(',', array_fill(0, count($propertyIds), '?'));
    $checkParams = array_merge($propertyIds, [$tenantId]);
    $checkStmt = analyticspro_db()->prepare("SELECT id FROM properties WHERE id IN ($placeholders) AND user_id = ?");
    $checkStmt->execute($checkParams);
    $accessibleIds = array_map(static fn ($row): int => (int) $row['id'], $checkStmt->fetchAll() ?: []);
    if (count($accessibleIds) !== count($propertyIds)) {
        throw new RuntimeException('Immobile non accessibile.');
    }

    $pdo = analyticspro_db();
    $pdo->beginTransaction();
    foreach ($propertyIds as $targetId) {
        foreach ([
            'DELETE FROM property_notes WHERE property_id = :id',
            'DELETE FROM property_assignments WHERE property_id = :id',
            'DELETE FROM property_status_history WHERE property_id = :id',
            'DELETE FROM property_owners WHERE property_id = :id',
        ] as $sql) {
            $pdo->prepare($sql)->execute(['id' => $targetId]);
        }
    }

    $deletePlaceholders = implode(',', array_fill(0, count($propertyIds), '?'));
    $deleteParams = array_merge($propertyIds, [$tenantId]);
    $stmt = $pdo->prepare("DELETE FROM properties WHERE id IN ($deletePlaceholders) AND user_id = ?");
    $stmt->execute($deleteParams);
    if ($stmt->rowCount() < count($propertyIds)) {
        throw new RuntimeException('Nessun immobile eliminato.');
    }

    $pdo->commit();
    analyticspro_json([
        'ok' => true,
        'deleted_property_id' => $propertyIds[0],
        'deleted_property_ids' => $propertyIds,
        'deleted_properties' => count($propertyIds),
    ]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    analyticspro_json(['ok' => false, 'error' => $exception->getMessage()], 422);
}
