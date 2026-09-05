<?php

declare(strict_types=1);

function analyticspro_visible_property_scope(array $user, string $mode, ?int $filterSubuserId = null): array
{
    $joins = [];
    $where = [];
    $params = [];

    if (($user['role'] ?? '') === 'admin') {
        $tenantId = analyticspro_current_tenant_id();
        if ($tenantId !== null) {
            $where[] = 'p.user_id = :tenant_id';
            $params['tenant_id'] = $tenantId;
        }
    } elseif (($user['role'] ?? '') === 'user') {
        $where[] = 'p.user_id = :tenant_id';
        $params['tenant_id'] = (int) $user['id'];
    } else {
        $where[] = 'p.user_id = :tenant_id';
        $params['tenant_id'] = (int) $user['parent_user_id'];
    }

    if ($mode === 'assigned') {
        if (($user['role'] ?? '') === 'subuser') {
            // Subuser: vede solo i propri immobili assegnati
            $joins[] = 'INNER JOIN property_assignments pa_filter ON pa_filter.property_id = p.id';
            $where[] = 'pa_filter.subuser_id = :assignment_subuser';
            $params['assignment_subuser'] = (int) $user['id'];
        } elseif ($filterSubuserId) {
            // Tenant con filtro specifico: vede gli immobili di quel subutente
            $joins[] = 'INNER JOIN property_assignments pa_filter ON pa_filter.property_id = p.id';
            $where[] = 'pa_filter.subuser_id = :assignment_subuser';
            $params['assignment_subuser'] = $filterSubuserId;
        }
        // Tenant senza filtro subutente: vede TUTTI gli immobili (assegnati e non)
        // così può assegnarli ai subutenti direttamente dalla pagina "Marker assegnati"
    }

    return [$joins, $where, $params];
}

function analyticspro_property_can_edit(array $user, array $property): bool
{
    if (($user['role'] ?? '') === 'admin' || ($user['role'] ?? '') === 'user') {
        return true;
    }

    $permissions = analyticspro_get_subuser_permissions((int) $user['id']);
    if (!empty($permissions['can_edit_all_markers'])) {
        return true;
    }

    foreach ($property['assignments'] as $assignment) {
        if ((int) $assignment['subuser_id'] === (int) $user['id']) {
            return true;
        }
    }

    return false;
}

function analyticspro_fetch_properties_payload(array $user, string $mode = 'all', ?int $filterSubuserId = null): array
{
    [$joins, $where, $params] = analyticspro_visible_property_scope($user, $mode, $filterSubuserId);
    $sql = 'SELECT DISTINCT p.*, tenant.nome AS tenant_nome, tenant.cognome AS tenant_cognome FROM properties p JOIN users tenant ON tenant.id = p.user_id ' . implode(' ', $joins);
    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    $sql .= ' ORDER BY p.updated_at DESC, p.id DESC';

    $stmt = analyticspro_db()->prepare($sql);
    $stmt->execute($params);
    $properties = $stmt->fetchAll();
    if (!$properties) {
        return ['properties' => [], 'subusers' => []];
    }

    $propertyIds = array_map(static fn ($property) => (int) $property['id'], $properties);
    $placeholders = implode(',', array_fill(0, count($propertyIds), '?'));

    $ownersStmt = analyticspro_db()->prepare("SELECT * FROM property_owners WHERE property_id IN ($placeholders) AND is_current = 1 ORDER BY id ASC");
    $ownersStmt->execute($propertyIds);
    $ownersByProperty = [];
    foreach ($ownersStmt->fetchAll() as $owner) {
        $ownersByProperty[(int) $owner['property_id']][] = $owner;
    }

    $notesStmt = analyticspro_db()->prepare("SELECT * FROM property_notes WHERE property_id IN ($placeholders) ORDER BY created_at ASC, id ASC");
    $notesStmt->execute($propertyIds);
    $notesByProperty = [];
    foreach ($notesStmt->fetchAll() as $note) {
        $notesByProperty[(int) $note['property_id']][] = $note;
    }

    $assignStmt = analyticspro_db()->prepare("SELECT pa.property_id, pa.subuser_id, CONCAT(u.nome, ' ', u.cognome) AS subuser_name FROM property_assignments pa JOIN users u ON u.id = pa.subuser_id WHERE pa.property_id IN ($placeholders) ORDER BY subuser_name");
    $assignStmt->execute($propertyIds);
    $assignmentsByProperty = [];
    foreach ($assignStmt->fetchAll() as $assignment) {
        $assignmentsByProperty[(int) $assignment['property_id']][] = $assignment;
    }

    $subusers = [];
    $tenantIds = array_values(array_unique(array_map(static fn ($property) => (int) $property['user_id'], $properties)));
    if (count($tenantIds) === 1) {
        $subusers = analyticspro_fetch_subusers($tenantIds[0]);
    }

    $payload = [];
    foreach ($properties as $property) {
        $propertyId = (int) $property['id'];
        $showPhone = analyticspro_is_admin() || analyticspro_tenant_phone_visibility((int) $property['user_id']);
        $owners = [];
        foreach ($ownersByProperty[$propertyId] ?? [] as $owner) {
            $ownerPayload = [
                'tipo' => $owner['tipo'],
                'nome' => analyticspro_decrypt($owner['nome_enc']),
                'cognome' => analyticspro_decrypt($owner['cognome_enc']),
                'codice_fiscale' => analyticspro_decrypt($owner['codice_fiscale_enc']),
                'indirizzo' => analyticspro_decrypt($owner['indirizzo_enc']),
                'email' => analyticspro_decrypt($owner['email_enc']),
                'data_nascita' => $owner['data_nascita'],
                'genere' => $owner['genere'],
            ];
            if ($showPhone) {
                $ownerPayload['telefono'] = analyticspro_decrypt($owner['telefono_enc']);
            }
            $owners[] = $ownerPayload;
        }

        $notes = array_map(static fn ($note) => [
            'author_name_snapshot' => $note['author_name_snapshot'],
            'testo' => $note['testo'],
            'created_at' => $note['created_at'],
        ], $notesByProperty[$propertyId] ?? []);

        $assignments = array_map(static fn ($assignment) => [
            'subuser_id' => (int) $assignment['subuser_id'],
            'subuser_name' => $assignment['subuser_name'],
        ], $assignmentsByProperty[$propertyId] ?? []);

        $record = $property;
        $record['owners'] = $owners;
        $record['notes'] = $notes;
        $record['assignments'] = $assignments;
        $record['tenant_name'] = trim(($property['tenant_nome'] ?? '') . ' ' . ($property['tenant_cognome'] ?? ''));
        $record['can_edit'] = analyticspro_property_can_edit($user, $record);
        $record['can_delete'] = ($user['role'] ?? '') === 'user';
        $record['is_assigned'] = !empty($record['assignments']);
        $payload[] = $record;
    }

    return ['properties' => $payload, 'subusers' => $subusers];
}
