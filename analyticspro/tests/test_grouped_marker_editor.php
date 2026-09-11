<?php

declare(strict_types=1);

/**
 * Test: i gruppi mappa conservano gli id reali per salvataggi immobile/intestatario.
 *
 * Exit code: 0 = pass, 1 = fail.
 */

function group_properties_by_unit(array $properties): array
{
    $groups = [];
    foreach ($properties as $property) {
        $comuneKey = strtoupper(trim((string) ($property['cod_catastale'] ?? ''))) !== ''
            ? 'COD:' . strtoupper(trim((string) ($property['cod_catastale'] ?? '')))
            : 'COM:' . strtoupper(trim((string) ($property['comune'] ?? '')));
        $key = implode('|', [
            strtoupper(trim((string) ($property['provincia'] ?? ''))),
            $comuneKey,
            strtoupper(trim((string) ($property['comune'] ?? ''))),
            strtoupper(trim((string) ($property['sezione'] ?? ''))),
            strtoupper(trim((string) ($property['foglio'] ?? ''))),
            strtoupper(trim((string) ($property['particella'] ?? ''))),
            strtoupper(trim((string) ($property['subalterno'] ?? ''))),
            (string) ($property['user_id'] ?? ''),
        ]);
        $groups[$key][] = $property;
    }

    $result = [];
    foreach ($groups as $propertiesInGroup) {
        $primary = $propertiesInGroup[0];
        $owners = [];
        $seen = [];
        foreach ($propertiesInGroup as $property) {
            foreach (($property['owners'] ?? []) as $owner) {
                $ownerKey = owner_group_key($owner, count($owners));
                if (!isset($seen[$ownerKey])) {
                    $owner['_sourcePropertyId'] = (int) $property['id'];
                    $owner['_canEdit'] = !empty($property['can_edit']);
                    $owner['_groupOwnerKey'] = $ownerKey;
                    $owners[] = $owner;
                    $seen[$ownerKey] = count($owners) - 1;
                    continue;
                }
                $existingIndex = $seen[$ownerKey];
                if (empty($owners[$existingIndex]['_canEdit']) && !empty($property['can_edit'])) {
                    $owners[$existingIndex]['_canEdit'] = true;
                    $owners[$existingIndex]['_sourcePropertyId'] = (int) $property['id'];
                    $owners[$existingIndex]['id'] = $owner['id'];
                    if (empty($owners[$existingIndex]['telefono']) && !empty($owner['telefono'])) {
                        $owners[$existingIndex]['telefono'] = $owner['telefono'];
                    }
                }
            }
        }
        $primary['owners'] = $owners;
        $primary['_groupIds'] = array_map(static fn (array $property): int => (int) $property['id'], $propertiesInGroup);
        $primary['_editableGroupIds'] = array_values(array_map(
            static fn (array $property): int => (int) $property['id'],
            array_filter($propertiesInGroup, static fn (array $property): bool => !empty($property['can_edit']))
        ));
        $primary['can_edit'] = $primary['_editableGroupIds'] !== [];
        $result[] = $primary;
    }

    return $result;
}

function owner_group_key(array $owner, int $fallbackIndex): string
{
    $ownerCf = trim((string) ($owner['codice_fiscale'] ?? ''));
    if ($ownerCf !== '') {
        return $ownerCf;
    }
    if (!empty($owner['id'])) {
        return 'ID:' . (string) $owner['id'];
    }
    return '__idx_' . (string) $fallbackIndex;
}

$properties = [
    [
        'id' => 101,
        'user_id' => 5,
        'provincia' => 'MI',
        'comune' => 'Milano',
        'cod_catastale' => 'F205',
        'sezione' => '',
        'foglio' => '10',
        'particella' => '200',
        'subalterno' => 'A',
        'can_edit' => false,
        'owners' => [
            ['id' => 11, 'nome' => 'Alice', 'cognome' => 'Rossi', 'codice_fiscale' => 'RSSLCA80A01F205X', 'telefono' => '111111'],
        ],
    ],
    [
        'id' => 202,
        'user_id' => 5,
        'provincia' => 'MI',
        'comune' => 'MILANO',
        'cod_catastale' => 'F205',
        'sezione' => '',
        'foglio' => '10',
        'particella' => '200',
        'subalterno' => 'A',
        'can_edit' => true,
        'owners' => [
            ['id' => 22, 'nome' => 'Alice', 'cognome' => 'Rossi', 'codice_fiscale' => 'RSSLCA80A01F205X', 'telefono' => '222222'],
            ['id' => 23, 'nome' => 'Bruno', 'cognome' => 'Verdi', 'codice_fiscale' => 'VRDBRN80A01F205X', 'telefono' => '333333'],
            ['id' => 0, 'nome' => 'Carla', 'cognome' => 'Senza ID', 'codice_fiscale' => '', 'telefono' => '444444'],
        ],
    ],
];

$groups = group_properties_by_unit($properties);
$pass = true;
$errors = [];

if (count($groups) !== 1) {
    $pass = false;
    $errors[] = 'Atteso 1 gruppo, trovati ' . count($groups);
}

$group = $groups[0] ?? null;
if ($group === null) {
    $pass = false;
    $errors[] = 'Gruppo non disponibile';
} else {
    if (($group['_groupIds'] ?? []) !== [101, 202]) {
        $pass = false;
        $errors[] = 'Gli id gruppo attesi sono [101,202]';
    }
    if (($group['_editableGroupIds'] ?? []) !== [202]) {
        $pass = false;
        $errors[] = 'Gli id modificabili attesi sono [202]';
    }
    if (empty($group['can_edit'])) {
        $pass = false;
        $errors[] = 'Il gruppo deve risultare modificabile quando almeno una property è editabile';
    }
    if (count($group['owners'] ?? []) !== 3) {
        $pass = false;
        $errors[] = 'Gli intestatari unificati attesi sono 3';
    } else {
        $ownersByCf = [];
        $ownersByName = [];
        foreach ($group['owners'] as $owner) {
            $ownersByCf[$owner['codice_fiscale']] = $owner;
            $ownersByName[$owner['nome']] = $owner;
        }
        $alice = $ownersByCf['RSSLCA80A01F205X'] ?? null;
        $bruno = $ownersByCf['VRDBRN80A01F205X'] ?? null;
        $carla = $ownersByName['Carla'] ?? null;
        if (($alice['_sourcePropertyId'] ?? null) !== 202 || ($alice['id'] ?? null) !== 22) {
            $pass = false;
            $errors[] = 'Alice deve usare la property modificabile 202 come sourcePropertyId';
        }
        if (empty($alice['_canEdit'])) {
            $pass = false;
            $errors[] = 'Alice deve risultare modificabile grazie alla property 202';
        }
        if (($bruno['_sourcePropertyId'] ?? null) !== 202 || empty($bruno['_canEdit'])) {
            $pass = false;
            $errors[] = 'Bruno deve restare legato alla property 202 modificabile';
        }
        if (($carla['_groupOwnerKey'] ?? null) !== '__idx_2') {
            $pass = false;
            $errors[] = 'Gli owner senza id/CF devono ricevere una chiave stabile di fallback';
        }
    }
}

if ($pass) {
    echo "PASS: grouped marker editor metadata OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: $error\n";
}
exit(1);
