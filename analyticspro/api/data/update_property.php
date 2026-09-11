<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once ANALYTICSPRO_ROOT . '/includes/api_bootstrap.php';

analyticspro_api_guard();
require_once ANALYTICSPRO_ROOT . '/includes/property_repository.php';

analyticspro_api_require_auth();

try {
    $input = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    analyticspro_verify_csrf($input['csrf_token'] ?? null);
    $propertyId = (int) ($input['property_id'] ?? 0);
    $action = trim((string) ($input['action'] ?? ''));
    $propertyIds = [];
    if ($action === '' && is_array($input['property_ids'] ?? null)) {
        foreach ($input['property_ids'] as $candidateId) {
            $candidateId = (int) $candidateId;
            if ($candidateId > 0 && !in_array($candidateId, $propertyIds, true)) {
                $propertyIds[] = $candidateId;
            }
        }
    }
    if ($action !== '' && $propertyId <= 0) {
        throw new RuntimeException('Immobile non valido.');
    }
    if ($action === '' && $propertyId <= 0 && $propertyIds === []) {
        throw new RuntimeException('Immobile non valido.');
    }
    if ($action === '' && $propertyIds === []) {
        $propertyIds = [$propertyId];
    }

    $user = analyticspro_current_user();
    $payload = analyticspro_fetch_properties_payload($user, 'all');
    $propertiesById = [];
    foreach ($payload['properties'] as $candidate) {
        $propertiesById[(int) $candidate['id']] = $candidate;
    }

    $property = null;
    if ($propertyId > 0 && isset($propertiesById[$propertyId])) {
        $property = $propertiesById[$propertyId];
    }
    if ($action === '') {
        $validatedProperties = [];
        foreach ($propertyIds as $targetPropertyId) {
            if (!isset($propertiesById[$targetPropertyId])) {
                throw new RuntimeException('Immobile non accessibile.');
            }
            $targetProperty = $propertiesById[$targetPropertyId];
            if (!analyticspro_property_can_edit($user, $targetProperty)) {
                throw new RuntimeException('Non puoi modificare questo marker.');
            }
            $validatedProperties[] = $targetProperty;
        }
        if ($validatedProperties === []) {
            throw new RuntimeException('Immobile non accessibile.');
        }
        $property = $validatedProperties[0];
    }
    if ($action !== '' && !$property) {
        throw new RuntimeException('Immobile non accessibile.');
    }
    if ($action !== '' && !analyticspro_property_can_edit($user, $property)) {
        throw new RuntimeException('Non puoi modificare questo marker.');
    }
    if ($action === 'add_owner_phone') {
        $ownerId = (int) ($input['owner_id'] ?? 0);
        $phoneToAdd = trim((string) ($input['phone'] ?? ''));
        if ($ownerId <= 0 || $phoneToAdd === '') {
            throw new RuntimeException('Dati telefono non validi.');
        }
        if (preg_match('/^[0-9+\-\s]+$/', $phoneToAdd) !== 1) {
            throw new RuntimeException('Formato numero non valido.');
        }

        $showPhone = analyticspro_is_admin() || analyticspro_tenant_phone_visibility((int) $property['user_id']);
        if (!$showPhone) {
            throw new RuntimeException('Visibilità telefono non abilitata.');
        }

        $pdo = analyticspro_db();
        $pdo->beginTransaction();
        try {
            $ownerStmt = $pdo->prepare('SELECT po.id, po.telefono_enc FROM property_owners po INNER JOIN properties p ON p.id = po.property_id WHERE po.id = :id AND po.property_id = :property_id AND po.is_current = 1 AND p.user_id = :tenant_owner_id LIMIT 1 FOR UPDATE');
            $ownerStmt->execute([
                'id' => $ownerId,
                'property_id' => $propertyId,
                'tenant_owner_id' => (int) $property['user_id'],
            ]);
            $owner = $ownerStmt->fetch();
            if (!$owner) {
                throw new RuntimeException('Intestatario non trovato.');
            }

            $currentPhone = analyticspro_decrypt($owner['telefono_enc']);
            $updatedPhone = analyticspro_add_phone_value($currentPhone, $phoneToAdd);

            $updateStmt = $pdo->prepare('UPDATE property_owners po INNER JOIN properties p ON p.id = po.property_id SET po.telefono_enc = :telefono_enc, po.telefono_hash = :telefono_hash WHERE po.id = :id AND po.property_id = :property_id AND po.is_current = 1 AND p.user_id = :tenant_owner_id');
            $updateStmt->execute([
                'telefono_enc' => analyticspro_encrypt($updatedPhone),
                'telefono_hash' => analyticspro_hash($updatedPhone),
                'id' => $ownerId,
                'property_id' => $propertyId,
                'tenant_owner_id' => (int) $property['user_id'],
            ]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        analyticspro_json([
            'ok' => true,
            'owner_id' => $ownerId,
            'telefono' => $updatedPhone,
        ]);
    }

    if ($action === 'remove_owner_phone') {
        $ownerId = (int) ($input['owner_id'] ?? 0);
        $phoneToRemove = trim((string) ($input['phone'] ?? ''));
        if ($ownerId <= 0 || $phoneToRemove === '') {
            throw new RuntimeException('Dati telefono non validi.');
        }

        $showPhone = analyticspro_is_admin() || analyticspro_tenant_phone_visibility((int) $property['user_id']);
        if (!$showPhone) {
            throw new RuntimeException('Visibilità telefono non abilitata.');
        }

        $pdo = analyticspro_db();
        $pdo->beginTransaction();
        try {
            $ownerStmt = $pdo->prepare('SELECT po.id, po.telefono_enc FROM property_owners po INNER JOIN properties p ON p.id = po.property_id WHERE po.id = :id AND po.property_id = :property_id AND po.is_current = 1 AND p.user_id = :tenant_owner_id LIMIT 1 FOR UPDATE');
            $ownerStmt->execute([
                'id' => $ownerId,
                'property_id' => $propertyId,
                'tenant_owner_id' => (int) $property['user_id'],
            ]);
            $owner = $ownerStmt->fetch();
            if (!$owner) {
                throw new RuntimeException('Intestatario non trovato.');
            }

            $currentPhone = analyticspro_decrypt($owner['telefono_enc']);
            $currentList = analyticspro_split_phone_values($currentPhone);
            if (!in_array($phoneToRemove, $currentList, true)) {
                throw new RuntimeException('Numero non trovato.');
            }
            $updatedPhone = analyticspro_remove_phone_value($currentPhone, $phoneToRemove);

            $updateStmt = $pdo->prepare('UPDATE property_owners po INNER JOIN properties p ON p.id = po.property_id SET po.telefono_enc = :telefono_enc, po.telefono_hash = :telefono_hash WHERE po.id = :id AND po.property_id = :property_id AND po.is_current = 1 AND p.user_id = :tenant_owner_id');
            $updateStmt->execute([
                    'telefono_enc' => analyticspro_encrypt($updatedPhone),
                    'telefono_hash' => analyticspro_hash($updatedPhone),
                    'id' => $ownerId,
                    'property_id' => $propertyId,
                    'tenant_owner_id' => (int) $property['user_id'],
                ]);
            $pdo->commit();
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        analyticspro_json([
            'ok' => true,
            'owner_id' => $ownerId,
            'telefono' => $updatedPhone ?? '',
        ]);
    }

    $currentState = isset($property['stato']) && $property['stato'] !== '' ? (string) $property['stato'] : null;
    $incomingStateRaw = trim((string) ($input['stato'] ?? ($currentState ?? '')));
    $newState = $incomingStateRaw !== '' ? $incomingStateRaw : null;
    $allowedStates = array_keys(analyticspro_state_options());
    if ($newState !== null && !in_array($newState, $allowedStates, true)) {
        throw new RuntimeException('Stato non valido.');
    }

    $newColor = trim((string) ($input['colore_marker'] ?? ''));
    $currentColor = trim((string) ($property['colore_marker'] ?? ''));
    if ($newColor !== '' && $newColor !== $currentColor && !in_array($newColor, analyticspro_allowed_marker_colors(), true)) {
        throw new RuntimeException('Colore non consentito.');
    }
    if ($newColor === '') {
        $newColor = $currentState !== $newState ? analyticspro_default_color_for_state((string) ($newState ?? '')) : (string) $property['colore_marker'];
    }

    $pdo = analyticspro_db();
    $pdo->beginTransaction();
    try {
        $updatePropertyStmt = $pdo->prepare('UPDATE properties SET stato = :stato, stato_personalizzato = :stato_personalizzato, colore_marker = :colore_marker WHERE id = :id');
        $statusHistoryStmt = $pdo->prepare('INSERT INTO property_status_history (property_id, changed_by, stato_precedente, stato_nuovo) VALUES (:property_id, :changed_by, :stato_precedente, :stato_nuovo)');
        $noteStmt = $pdo->prepare('INSERT INTO property_notes (property_id, author_id, author_name_snapshot, testo) VALUES (:property_id, :author_id, :author_name_snapshot, :testo)');
        $deleteAssignmentsStmt = $pdo->prepare('DELETE FROM property_assignments WHERE property_id = :property_id');
        $insertAssignmentStmt = $pdo->prepare('INSERT INTO property_assignments (property_id, subuser_id, assigned_by) VALUES (:property_id, :subuser_id, :assigned_by)');

        $stateOptions = analyticspro_state_options();
        $stateLabel = $newState !== null ? ($stateOptions[$newState] ?? $newState) : 'Non impostato';
        $stateNote = analyticspro_note_log_state_change($stateLabel);
        $note = analyticspro_note_log_manual_note((string) ($input['note'] ?? ''));
        $customState = trim((string) ($input['stato_personalizzato'] ?? '')) ?: null;

        $incomingAssignments = null;
        if (($user['role'] ?? '') !== 'subuser' && is_array($input['assignments'] ?? null)) {
            $tenantOwnerId = (int) $property['user_id'];
            foreach ($validatedProperties as $validatedProperty) {
                if ((int) $validatedProperty['user_id'] !== $tenantOwnerId) {
                    throw new RuntimeException('Le assegnazioni del gruppo devono appartenere allo stesso tenant.');
                }
            }
            $tenantSubusers = analyticspro_fetch_subusers($tenantOwnerId);
            $allowedSubusers = array_map(static fn ($subuser) => (int) $subuser['id'], $tenantSubusers);
            $incomingAssignments = array_values(array_unique(array_map('intval', $input['assignments'])));
            $incomingAssignments = array_values(array_filter($incomingAssignments, static fn ($subuserId) => in_array($subuserId, $allowedSubusers, true)));
        }

        foreach ($validatedProperties as $validatedProperty) {
            $validatedPropertyId = (int) $validatedProperty['id'];
            $validatedCurrentState = isset($validatedProperty['stato']) && $validatedProperty['stato'] !== '' ? (string) $validatedProperty['stato'] : null;
            $validatedColor = $newColor;
            if ($validatedColor === '') {
                $validatedColor = $validatedCurrentState !== $newState ? analyticspro_default_color_for_state((string) ($newState ?? '')) : (string) $validatedProperty['colore_marker'];
            }

            $updatePropertyStmt->execute([
                'stato' => $newState,
                'stato_personalizzato' => $customState,
                'colore_marker' => $validatedColor,
                'id' => $validatedPropertyId,
            ]);

            if ($validatedCurrentState !== $newState) {
                $statusHistoryStmt->execute([
                    'property_id' => $validatedPropertyId,
                    'changed_by' => $user['id'],
                    'stato_precedente' => $validatedCurrentState,
                    'stato_nuovo' => $newState ?? '',
                ]);
                if ($stateNote !== '') {
                    $noteStmt->execute([
                        'property_id' => $validatedPropertyId,
                        'author_id' => $user['id'],
                        'author_name_snapshot' => analyticspro_full_name($user),
                        'testo' => $stateNote,
                    ]);
                }
            }

            if ($note !== '') {
                $noteStmt->execute([
                    'property_id' => $validatedPropertyId,
                    'author_id' => $user['id'],
                    'author_name_snapshot' => analyticspro_full_name($user),
                    'testo' => $note,
                ]);
            }

            if (is_array($incomingAssignments)) {
                $deleteAssignmentsStmt->execute(['property_id' => $validatedPropertyId]);
                foreach ($incomingAssignments as $subuserId) {
                    $insertAssignmentStmt->execute([
                        'property_id' => $validatedPropertyId,
                        'subuser_id' => $subuserId,
                        'assigned_by' => $user['id'],
                    ]);
                }
            }
        }

        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }
    analyticspro_json(['ok' => true, 'updated_ids' => $propertyIds]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    analyticspro_json(['ok' => false, 'error' => $exception->getMessage()], 422);
}
