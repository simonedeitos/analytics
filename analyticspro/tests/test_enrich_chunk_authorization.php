<?php

declare(strict_types=1);

/**
 * Test: matrice autorizzazione enrich_chunk.
 *
 * Exit code: 0 = pass, 1 = fail.
 */

require dirname(__DIR__) . '/includes/importer.php';

function simulate_enrich_chunk_auth(
    bool $isAdmin,
    bool $isSubuser,
    bool $canImport,
    int $batchId,
    ?int $currentTenantId,
    ?int $batchTenantId,
    bool $hasUser = true
): string {
    $permissionScope = analyticspro_enrich_chunk_authorize_permission($isSubuser, $canImport);
    if (!($permissionScope['ok'] ?? false)) {
        return (string) ($permissionScope['error_code'] ?? 'forbidden');
    }

    $scope = analyticspro_enrich_chunk_authorize_scope($batchId, $isAdmin, $currentTenantId, $hasUser);
    if (!($scope['ok'] ?? false)) {
        return (string) ($scope['error_code'] ?? 'forbidden');
    }

    if ($batchId === 0) {
        return 'ok';
    }

    if ($batchTenantId === null) {
        return 'batch_not_found';
    }

    if ($isAdmin) {
        return 'ok';
    }

    return $currentTenantId === $batchTenantId ? 'ok' : 'batch_not_found';
}

$pass = true;
$errors = [];

$cases = [
    [
        'name' => 'admin + globale',
        'result' => simulate_enrich_chunk_auth(true, false, true, 0, 10, null),
        'expected' => 'ok',
    ],
    [
        'name' => 'non admin + globale',
        'result' => simulate_enrich_chunk_auth(false, false, true, 0, 10, null),
        'expected' => 'forbidden',
    ],
    [
        'name' => 'non admin + batch proprio tenant',
        'result' => simulate_enrich_chunk_auth(false, false, true, 12, 10, 10),
        'expected' => 'ok',
    ],
    [
        'name' => 'non admin + batch altro tenant',
        'result' => simulate_enrich_chunk_auth(false, false, true, 12, 10, 77),
        'expected' => 'batch_not_found',
    ],
    [
        'name' => 'non admin + batch inesistente',
        'result' => simulate_enrich_chunk_auth(false, false, true, 12, 10, null),
        'expected' => 'batch_not_found',
    ],
    [
        'name' => 'admin + batch inesistente',
        'result' => simulate_enrich_chunk_auth(true, false, true, 12, 10, null),
        'expected' => 'batch_not_found',
    ],
    [
        'name' => 'subuser senza can_import',
        'result' => simulate_enrich_chunk_auth(false, true, false, 12, 10, 10),
        'expected' => 'forbidden',
    ],
    [
        'name' => 'subuser senza can_import in modalità globale',
        'result' => simulate_enrich_chunk_auth(false, true, false, 0, 10, null),
        'expected' => 'forbidden',
    ],
    [
        'name' => 'subuser con can_import batch proprio',
        'result' => simulate_enrich_chunk_auth(false, true, true, 12, 10, 10),
        'expected' => 'ok',
    ],
    [
        'name' => 'batch_id negativo',
        'result' => simulate_enrich_chunk_auth(true, false, true, -1, 10, 10),
        'expected' => 'invalid_param',
    ],
    [
        'name' => 'non admin senza tenant corrente',
        'result' => simulate_enrich_chunk_auth(false, false, true, 12, null, 10),
        'expected' => 'forbidden',
    ],
    [
        'name' => 'non admin senza utente autenticato',
        'result' => simulate_enrich_chunk_auth(false, false, true, 12, 10, 10, false),
        'expected' => 'forbidden',
    ],
];

foreach ($cases as $case) {
    if ($case['result'] !== $case['expected']) {
        $pass = false;
        $errors[] = $case['name'] . ": atteso {$case['expected']}, ottenuto {$case['result']}";
    }
}

if ($pass) {
    echo "PASS: enrich_chunk authorization matrix OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: $error\n";
}
exit(1);
