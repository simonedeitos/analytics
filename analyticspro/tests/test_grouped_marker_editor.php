<?php

declare(strict_types=1);

require __DIR__ . '/../includes/importer.php';

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

function group_properties_by_unit(array $properties): array
{
    $canonicalGroups = analyticspro_group_records_by_canonical_unit($properties);
    $result = [];
    foreach ($canonicalGroups as $propertiesInGroup) {
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
                    $owner['quota'] = trim((string) ($owner['quota'] ?? '')) !== '' ? $owner['quota'] : ($property['quota'] ?? '');
                    $owner['titolarita'] = trim((string) ($owner['titolarita'] ?? '')) !== '' ? $owner['titolarita'] : ($property['titolarita'] ?? '');
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
        $latSum = 0.0;
        $lngSum = 0.0;
        $coordCount = 0;
        foreach ($propertiesInGroup as $property) {
            if (!isset($property['lat'], $property['lng']) || !is_numeric($property['lat']) || !is_numeric($property['lng'])) {
                continue;
            }
            $latSum += (float) $property['lat'];
            $lngSum += (float) $property['lng'];
            $coordCount++;
        }
        $primary['lat'] = $coordCount > 0 ? $latSum / $coordCount : null;
        $primary['lng'] = $coordCount > 0 ? $lngSum / $coordCount : null;
        $result[] = $primary;
    }

    return $result;
}

$properties = [
    [
        'id' => 101,
        'tenant_id' => 5,
        'provincia' => 'MI',
        'comune' => 'Milano',
        'cod_catastale' => 'F205',
        'sezione' => '',
        'foglio' => '0010',
        'particella' => '0200',
        'subalterno' => '0001',
        'lat' => 45.1000,
        'lng' => 9.1000,
        'can_edit' => false,
        'owners' => [
            ['id' => 11, 'nome' => 'Alice', 'cognome' => 'Rossi', 'codice_fiscale' => 'RSSLCA80A01F205X', 'telefono' => '111111', 'quota' => '1/2', 'titolarita' => 'Piena proprietà'],
        ],
    ],
    [
        'id' => 202,
        'tenant_id' => 5,
        'provincia' => 'MI',
        'comune' => 'MILANO',
        'cod_catastale' => '',
        'sezione' => null,
        'foglio' => '10',
        'particella' => '200',
        'subalterno' => '1',
        'lat' => 45.3000,
        'lng' => 9.3000,
        'can_edit' => true,
        'owners' => [
            ['id' => 22, 'nome' => 'Alice', 'cognome' => 'Rossi', 'codice_fiscale' => 'RSSLCA80A01F205X', 'telefono' => '222222', 'quota' => '1/2', 'titolarita' => 'Piena proprietà'],
            ['id' => 23, 'nome' => 'Bruno', 'cognome' => 'Verdi', 'codice_fiscale' => 'VRDBRN80A01F205X', 'telefono' => '333333', 'quota' => '1/2', 'titolarita' => 'Piena proprietà'],
            ['id' => 0, 'nome' => 'Carla', 'cognome' => 'Senza ID', 'codice_fiscale' => '', 'telefono' => '444444'],
        ],
    ],
    [
        'id' => 303,
        'tenant_id' => 5,
        'provincia' => 'MI',
        'comune' => 'Milano',
        'cod_catastale' => 'F205',
        'sezione' => '',
        'foglio' => '10',
        'particella' => '200',
        'subalterno' => '',
        'can_edit' => true,
        'owners' => [
            ['id' => 31, 'nome' => 'Empty', 'cognome' => 'Sub 1', 'codice_fiscale' => 'EMPTYSUB11111111', 'telefono' => '555555'],
        ],
    ],
    [
        'id' => 404,
        'tenant_id' => 5,
        'provincia' => 'MI',
        'comune' => 'Milano',
        'cod_catastale' => 'F205',
        'sezione' => '',
        'foglio' => '10',
        'particella' => '201',
        'subalterno' => '',
        'can_edit' => true,
        'owners' => [
            ['id' => 41, 'nome' => 'Empty', 'cognome' => 'Sub 2', 'codice_fiscale' => 'EMPTYSUB22222222', 'telefono' => '666666'],
        ],
    ],
    [
        'id' => 505,
        'tenant_id' => 5,
        'provincia' => 'MI',
        'comune' => 'Milano',
        'cod_catastale' => 'F205',
        'sezione' => '',
        'foglio' => '10',
        'particella' => '201',
        'subalterno' => '1',
        'can_edit' => true,
        'owners' => [
            ['id' => 51, 'nome' => 'Candidate', 'cognome' => 'Uno', 'codice_fiscale' => 'CANDIDATE1111111', 'telefono' => '777777'],
        ],
    ],
    [
        'id' => 606,
        'tenant_id' => 5,
        'provincia' => 'MI',
        'comune' => 'Milano',
        'cod_catastale' => 'F205',
        'sezione' => '',
        'foglio' => '10',
        'particella' => '201',
        'subalterno' => '2',
        'can_edit' => true,
        'owners' => [
            ['id' => 61, 'nome' => 'Candidate', 'cognome' => 'Due', 'codice_fiscale' => 'CANDIDATE2222222', 'telefono' => '888888'],
        ],
    ],
];

$groups = group_properties_by_unit($properties);
$pass = true;
$errors = [];

if (count($groups) !== 4) {
    $pass = false;
    $errors[] = 'Attesi 4 gruppi, trovati ' . count($groups);
}

$mergedGroup = null;
$isolatedEmpty = 0;
foreach ($groups as $group) {
    $ids = $group['_groupIds'] ?? [];
    if ($ids === [101, 202, 303]) {
        $mergedGroup = $group;
        continue;
    }
    if (($group['_groupIds'] ?? []) === [404]) {
        $isolatedEmpty++;
    }
}

if ($mergedGroup === null) {
    $pass = false;
    $errors[] = 'La property con subalterno vuoto e candidato unico deve fondersi nel gruppo [101,202,303].';
} else {
    if (($mergedGroup['_editableGroupIds'] ?? []) !== [202, 303]) {
        $pass = false;
        $errors[] = 'Gli id modificabili attesi sono [202,303].';
    }
    if (count($mergedGroup['owners'] ?? []) !== 4) {
        $pass = false;
        $errors[] = 'Gli intestatari unificati attesi sono 4.';
    } else {
        $ownersByCf = [];
        $ownersByName = [];
        foreach ($mergedGroup['owners'] as $owner) {
            $ownersByCf[$owner['codice_fiscale']] = $owner;
            $ownersByName[$owner['nome']] = $owner;
        }
        $alice = $ownersByCf['RSSLCA80A01F205X'] ?? null;
        $bruno = $ownersByCf['VRDBRN80A01F205X'] ?? null;
        $empty = $ownersByCf['EMPTYSUB11111111'] ?? null;
        $carla = $ownersByName['Carla'] ?? null;
        if (($alice['_sourcePropertyId'] ?? null) !== 202 || ($alice['id'] ?? null) !== 22) {
            $pass = false;
            $errors[] = 'Alice deve usare la property modificabile 202 come sourcePropertyId.';
        }
        if (($bruno['quota'] ?? '') !== '1/2') {
            $pass = false;
            $errors[] = 'Bruno deve mantenere la quota per-intestatario 1/2.';
        }
        if (($empty['_sourcePropertyId'] ?? null) !== 303) {
            $pass = false;
            $errors[] = 'L\'owner con subalterno vuoto deve restare collegato alla property 303.';
        }
        if (($carla['_groupOwnerKey'] ?? null) !== '__idx_2') {
            $pass = false;
            $errors[] = 'Gli owner senza id/CF devono ricevere una chiave stabile di fallback.';
        }
    }
    if (abs((float) ($mergedGroup['lat'] ?? 0) - 45.2) > 0.00001 || abs((float) ($mergedGroup['lng'] ?? 0) - 9.2) > 0.00001) {
        $pass = false;
        $errors[] = 'La media coordinate del gruppo attesa è (45.2, 9.2).';
    }
}

if ($isolatedEmpty !== 1) {
    $pass = false;
    $errors[] = 'Il record con subalterno vuoto e candidati multipli deve restare separato.';
}

if ($pass) {
    echo "PASS: grouped marker editor metadata OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: $error\n";
}
exit(1);
