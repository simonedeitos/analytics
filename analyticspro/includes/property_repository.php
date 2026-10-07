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

function analyticspro_property_can_edit(array $user, array $property, ?array $subuserPermissions = null): bool
{
    if (($user['role'] ?? '') === 'admin' || ($user['role'] ?? '') === 'user') {
        return true;
    }

    $permissions = $subuserPermissions ?? analyticspro_get_subuser_permissions((int) $user['id']);
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

function analyticspro_fetch_properties_payload(array $user, string $mode = 'all', ?int $filterSubuserId = null, ?array $requestedIds = null, bool $mapSummary = false): array
{
    [$joins, $where, $params] = analyticspro_visible_property_scope($user, $mode, $filterSubuserId);
    if ($requestedIds !== null) {
        if ($requestedIds === []) {
            return ['properties' => [], 'subusers' => []];
        }
        $idPlaceholders = [];
        foreach (array_values(array_unique(array_map('intval', $requestedIds))) as $index => $id) {
            $key = 'property_id_' . $index;
            $idPlaceholders[] = ':' . $key;
            $params[$key] = $id;
        }
        $where[] = 'p.id IN (' . implode(',', $idPlaceholders) . ')';
    }
    $columns = $mapSummary
        ? 'p.id, p.user_id, p.provincia, p.comune, p.cod_catastale, p.sezione, p.foglio, p.particella, p.subalterno, p.rendita, p.categoria, p.indirizzo, p.civico, p.lat, p.lng, p.posizione_verificata, p.coord_source, p.stato, p.stato_personalizzato, p.colore_marker'
        : 'p.*';
    $sql = 'SELECT ' . $columns . ', tenant.nome AS tenant_nome, tenant.cognome AS tenant_cognome FROM properties p JOIN users tenant ON tenant.id = p.user_id ' . implode(' ', $joins);
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

    $ownersByProperty = [];
    $notesByProperty = [];
    if (!$mapSummary) {
        $ownersStmt = analyticspro_db()->prepare("SELECT * FROM property_owners WHERE property_id IN ($placeholders) AND is_current = 1 ORDER BY id ASC");
        $ownersStmt->execute($propertyIds);
        foreach ($ownersStmt->fetchAll() as $owner) {
            $ownersByProperty[(int) $owner['property_id']][] = $owner;
        }

        $notesStmt = analyticspro_db()->prepare("SELECT property_id, author_name_snapshot, testo, created_at FROM property_notes WHERE property_id IN ($placeholders) ORDER BY created_at ASC, id ASC");
        $notesStmt->execute($propertyIds);
        foreach ($notesStmt->fetchAll() as $note) {
            $notesByProperty[(int) $note['property_id']][] = $note;
        }
    }

    $assignStmt = analyticspro_db()->prepare("SELECT pa.property_id, pa.subuser_id, CONCAT(u.nome, ' ', u.cognome) AS subuser_name FROM property_assignments pa JOIN users u ON u.id = pa.subuser_id WHERE pa.property_id IN ($placeholders) ORDER BY subuser_name");
    $assignStmt->execute($propertyIds);
    $assignmentsByProperty = [];
    foreach ($assignStmt->fetchAll() as $assignment) {
        $assignmentsByProperty[(int) $assignment['property_id']][] = $assignment;
    }

    $subusers = [];
    $tenantIds = array_values(array_unique(array_map(static fn ($property) => (int) $property['user_id'], $properties)));
    if ($requestedIds === null && count($tenantIds) === 1) {
        $subusers = analyticspro_fetch_subusers($tenantIds[0]);
    }

    $payload = [];
    $subuserPermissions = ($user['role'] ?? '') === 'subuser'
        ? analyticspro_get_subuser_permissions((int) $user['id'])
        : [];
    $phoneVisibility = [];
    foreach ($properties as $property) {
        $propertyId = (int) $property['id'];
        $tenantId = (int) $property['user_id'];
        $showPhone = $phoneVisibility[$tenantId] ??= analyticspro_is_admin() || analyticspro_tenant_phone_visibility($tenantId);
        $owners = [];
        foreach ($ownersByProperty[$propertyId] ?? [] as $owner) {
            $ownerPayload = [
                'id' => (int) $owner['id'],
                'tipo' => $owner['tipo'],
                'nome' => analyticspro_decrypt($owner['nome_enc']),
                'cognome' => analyticspro_decrypt($owner['cognome_enc']),
                'codice_fiscale' => analyticspro_decrypt($owner['codice_fiscale_enc']),
                'indirizzo' => analyticspro_decrypt($owner['indirizzo_enc']),
                'email' => analyticspro_decrypt($owner['email_enc']),
                'data_nascita' => $owner['data_nascita'],
                'luogo_nascita' => analyticspro_decrypt($owner['luogo_nascita_enc'] ?? null),
                'genere' => $owner['genere'],
                'quota' => $owner['quota'] ?? null,
                'titolarita' => $owner['titolarita'] ?? null,
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
        $record['can_view_phone'] = $showPhone;
        $record['can_edit'] = analyticspro_property_can_edit($user, $record, $subuserPermissions);
        $record['can_delete'] = ($user['role'] ?? '') === 'user' && (int) ($property['user_id'] ?? 0) === (int) ($user['id'] ?? 0);
        $record['is_assigned'] = !empty($record['assignments']);
        if ($mapSummary) {
            unset($record['owners'], $record['notes']);
        }
        $payload[] = $record;
    }

    return ['properties' => $payload, 'subusers' => $subusers];
}
