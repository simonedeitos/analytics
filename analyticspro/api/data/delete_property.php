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
    if ($propertyId <= 0) {
        throw new RuntimeException('Immobile non valido.');
    }

    $tenantId = analyticspro_current_tenant_id();
    if ($tenantId === null) {
        throw new RuntimeException('Tenant non disponibile.');
    }

    $checkStmt = analyticspro_db()->prepare('SELECT id FROM properties WHERE id = :id AND user_id = :user_id LIMIT 1');
    $checkStmt->execute([
        'id' => $propertyId,
        'user_id' => $tenantId,
    ]);
    if (!$checkStmt->fetch()) {
        throw new RuntimeException('Immobile non accessibile.');
    }

    $pdo = analyticspro_db();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('DELETE FROM properties WHERE id = :id AND user_id = :user_id LIMIT 1');
    $stmt->execute([
        'id' => $propertyId,
        'user_id' => $tenantId,
    ]);
    if ($stmt->rowCount() < 1) {
        throw new RuntimeException('Nessun immobile eliminato.');
    }

    $pdo->commit();
    analyticspro_json(['ok' => true, 'deleted_property_id' => $propertyId, 'deleted_properties' => 1]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    analyticspro_json(['ok' => false, 'error' => $exception->getMessage()], 422);
}
