<?php

declare(strict_types=1);

function analyticspro_disable_subuser(PDO $pdo, int $ownerId, int $targetId, string $mode, ?int $recipientId = null): void
{
    if (!in_array($mode, ['keep', 'reassign', 'unassign'], true)) {
        throw new RuntimeException('Gestione assegnazioni non valida.');
    }
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Disabilitazione non disponibile durante un’altra transazione.');
    }

    $pdo->beginTransaction();
    try {
        // The tenant lock serializes assignment changes and team deactivations.
        $ownerStmt = $pdo->prepare("SELECT id FROM users WHERE id = :id AND role = 'user' AND status = 'active' FOR UPDATE");
        $ownerStmt->execute(['id' => $ownerId]);
        if (!$ownerStmt->fetch()) {
            throw new RuntimeException('Accesso non consentito.');
        }

        $targetStmt = $pdo->prepare("SELECT id FROM users WHERE id = :id AND parent_user_id = :owner_id AND role = 'subuser' AND status = 'active' FOR UPDATE");
        $targetStmt->execute(['id' => $targetId, 'owner_id' => $ownerId]);
        if (!$targetStmt->fetch()) {
            throw new RuntimeException('Subutente non valido o già disabilitato.');
        }

        if ($mode !== 'keep') {
            $assignmentStmt = $pdo->prepare('SELECT pa.property_id FROM property_assignments pa
                LEFT JOIN properties p ON p.id = pa.property_id
                WHERE pa.subuser_id = :target_id AND (p.id IS NULL OR p.user_id <> :owner_id)
                LIMIT 1 FOR UPDATE');
            $assignmentStmt->execute(['target_id' => $targetId, 'owner_id' => $ownerId]);
            if ($assignmentStmt->fetch()) {
                throw new RuntimeException('Assegnazioni incoerenti: contatta un amministratore.');
            }
        }

        if ($mode === 'reassign') {
            if (!$recipientId || $recipientId === $targetId) {
                throw new RuntimeException('Seleziona un altro collaboratore attivo.');
            }
            $recipientStmt = $pdo->prepare("SELECT id FROM users WHERE id = :id AND parent_user_id = :owner_id AND role = 'subuser' AND status = 'active' FOR UPDATE");
            $recipientStmt->execute(['id' => $recipientId, 'owner_id' => $ownerId]);
            if (!$recipientStmt->fetch()) {
                throw new RuntimeException('Destinatario non valido.');
            }

            $pdo->prepare('INSERT INTO property_assignments (property_id, subuser_id, assigned_by, assigned_at)
                SELECT pa.property_id, :recipient_id, :assigned_by, NOW()
                FROM property_assignments pa
                INNER JOIN properties p ON p.id = pa.property_id
                WHERE pa.subuser_id = :target_id AND p.user_id = :owner_id
                ON DUPLICATE KEY UPDATE property_id = VALUES(property_id)')
                ->execute(['recipient_id' => $recipientId, 'assigned_by' => $ownerId, 'target_id' => $targetId, 'owner_id' => $ownerId]);
        }

        if ($mode !== 'keep') {
            $pdo->prepare('DELETE FROM property_assignments WHERE subuser_id = :target_id')
                ->execute(['target_id' => $targetId]);
        }
        $pdo->prepare("UPDATE users SET status = 'disabled', remember_token = NULL, remember_token_expires_at = NULL WHERE id = :id")
            ->execute(['id' => $targetId]);
        $pdo->prepare('DELETE FROM user_sessions WHERE user_id = :user_id')
            ->execute(['user_id' => $targetId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}

function analyticspro_reactivate_subuser(PDO $pdo, int $ownerId, int $targetId): void
{
    if ($pdo->inTransaction()) {
        throw new RuntimeException('Riattivazione non disponibile durante un’altra transazione.');
    }

    $pdo->beginTransaction();
    try {
        $ownerStmt = $pdo->prepare("SELECT id FROM users WHERE id = :id AND role = 'user' AND status = 'active' FOR UPDATE");
        $ownerStmt->execute(['id' => $ownerId]);
        if (!$ownerStmt->fetch()) {
            throw new RuntimeException('Accesso non consentito.');
        }

        $targetStmt = $pdo->prepare("SELECT id FROM users WHERE id = :id AND parent_user_id = :owner_id AND role = 'subuser' AND status = 'disabled' FOR UPDATE");
        $targetStmt->execute(['id' => $targetId, 'owner_id' => $ownerId]);
        if (!$targetStmt->fetch()) {
            throw new RuntimeException('Subutente non valido o non disabilitato.');
        }

        $pdo->prepare("UPDATE users SET status = 'active' WHERE id = :id")
            ->execute(['id' => $targetId]);
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
}
