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
    if ($propertyId <= 0) {
        throw new RuntimeException('Immobile non valido.');
    }

    $user = analyticspro_current_user();
    $payload = analyticspro_fetch_properties_payload($user, 'all');
    $property = null;
    foreach ($payload['properties'] as $candidate) {
        if ((int) $candidate['id'] === $propertyId) {
            $property = $candidate;
            break;
        }
    }
    if (!$property) {
        throw new RuntimeException('Immobile non accessibile.');
    }
    if (!analyticspro_property_can_edit($user, $property)) {
        throw new RuntimeException('Non puoi modificare questo marker.');
    }

    $newState = (string) ($input['stato'] ?? $property['stato']);
    $allowedStates = array_keys(analyticspro_state_options());
    if (!in_array($newState, $allowedStates, true)) {
        throw new RuntimeException('Stato non valido.');
    }

    $newColor = trim((string) ($input['colore_marker'] ?? ''));
    $currentColor = trim((string) ($property['colore_marker'] ?? ''));
    if ($newColor !== '' && $newColor !== $currentColor && !in_array($newColor, analyticspro_allowed_marker_colors(), true)) {
        throw new RuntimeException('Colore non consentito.');
    }
    if ($newColor === '') {
        $newColor = $property['stato'] !== $newState ? analyticspro_default_color_for_state($newState) : (string) $property['colore_marker'];
    }

    $pdo = analyticspro_db();
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE properties SET stato = :stato, stato_personalizzato = :stato_personalizzato, colore_marker = :colore_marker WHERE id = :id')
        ->execute([
            'stato' => $newState,
            'stato_personalizzato' => trim((string) ($input['stato_personalizzato'] ?? '')) ?: null,
            'colore_marker' => $newColor,
            'id' => $propertyId,
        ]);

    if ((string) $property['stato'] !== $newState) {
        $pdo->prepare('INSERT INTO property_status_history (property_id, changed_by, stato_precedente, stato_nuovo) VALUES (:property_id, :changed_by, :stato_precedente, :stato_nuovo)')
            ->execute([
                'property_id' => $propertyId,
                'changed_by' => $user['id'],
                'stato_precedente' => $property['stato'],
                'stato_nuovo' => $newState,
            ]);
    }

    $note = trim((string) ($input['note'] ?? ''));
    if ($note !== '') {
        $pdo->prepare('INSERT INTO property_notes (property_id, author_id, author_name_snapshot, testo) VALUES (:property_id, :author_id, :author_name_snapshot, :testo)')
            ->execute([
                'property_id' => $propertyId,
                'author_id' => $user['id'],
                'author_name_snapshot' => analyticspro_full_name($user),
                'testo' => $note,
            ]);
    }

    if (($user['role'] ?? '') !== 'subuser' && is_array($input['assignments'] ?? null)) {
        $tenantSubusers = analyticspro_fetch_subusers((int) $property['user_id']);
        $allowedSubusers = array_map(static fn ($subuser) => (int) $subuser['id'], $tenantSubusers);
        $incoming = array_values(array_unique(array_map('intval', $input['assignments'])));
        $incoming = array_values(array_filter($incoming, static fn ($subuserId) => in_array($subuserId, $allowedSubusers, true)));
        $pdo->prepare('DELETE FROM property_assignments WHERE property_id = :property_id')->execute(['property_id' => $propertyId]);
        $insert = $pdo->prepare('INSERT INTO property_assignments (property_id, subuser_id, assigned_by) VALUES (:property_id, :subuser_id, :assigned_by)');
        foreach ($incoming as $subuserId) {
            $insert->execute([
                'property_id' => $propertyId,
                'subuser_id' => $subuserId,
                'assigned_by' => $user['id'],
            ]);
        }
    }

    $pdo->commit();
    analyticspro_json(['ok' => true]);
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    analyticspro_json(['ok' => false, 'error' => $exception->getMessage()], 422);
}
