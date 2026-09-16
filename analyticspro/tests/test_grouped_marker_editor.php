<?php

declare(strict_types=1);

/**
 * Test: i gruppi mappa conservano gli id reali per salvataggi immobile/intestatario.
 *
 * Exit code: 0 = pass, 1 = fail.
 */

function group_properties_by_unit(array $properties): array
{
    $normalizeText = static function ($value): string {
        $value = strtoupper(trim((string) $value));
        $value = strtr($value, ['À' => 'A', 'È' => 'E', 'É' => 'E', 'Ì' => 'I', 'Ò' => 'O', 'Ù' => 'U']);
        $value = preg_replace('/[^A-Z0-9\/]+/', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    };
    $normalizeCad = static function ($value): string {
        $value = strtoupper(trim((string) $value));
        $value = preg_replace('/\s+/', '', $value) ?? $value;
        if ($value === '') {
            return '';
        }
        if (preg_match('/^([0-9]+)(.*)$/', $value, $matches) !== 1) {
            return $value;
        }
        $number = ltrim((string) ($matches[1] ?? ''), '0');
        if ($number === '') {
            $number = '0';
        }
        return $number . (string) ($matches[2] ?? '');
    };
    $groups = [];
    foreach ($properties as $index => $property) {
        $codCat = $normalizeText($property['cod_catastale'] ?? '');
        $comuneKey = $codCat !== ''
            ? 'COD:' . $codCat
            : 'COM:' . $normalizeText($property['comune'] ?? '');
        $subNorm = $normalizeCad($property['subalterno'] ?? '');
        $subKey = $subNorm !== '' ? $subNorm : ('SUB:__NONE__#' . (string) ($property['id'] ?? $index));
        $key = implode('|', [
            $normalizeText($property['provincia'] ?? ''),
            $comuneKey,
            $normalizeText($property['sezione'] ?? ''),
            $normalizeCad($property['foglio'] ?? ''),
            $normalizeCad($property['particella'] ?? ''),
            $subKey,
            (string) ($property['tenant_id'] ?? $property['user_id'] ?? ''),
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
            ['id' => 11, 'nome' => 'Alice', 'cognome' => 'Rossi', 'codice_fiscale' => 'RSSLCA80A01F205X', 'telefono' => '111111'],
        ],
    ],
    [
        'id' => 202,
        'tenant_id' => 5,
        'provincia' => 'MI',
        'comune' => 'MILANO',
        'cod_catastale' => 'F205',
        'sezione' => null,
        'foglio' => '10',
        'particella' => '200',
        'subalterno' => '1',
        'lat' => 45.3000,
        'lng' => 9.3000,
        'can_edit' => true,
        'owners' => [
            ['id' => 22, 'nome' => 'Alice', 'cognome' => 'Rossi', 'codice_fiscale' => 'RSSLCA80A01F205X', 'telefono' => '222222'],
            ['id' => 23, 'nome' => 'Bruno', 'cognome' => 'Verdi', 'codice_fiscale' => 'VRDBRN80A01F205X', 'telefono' => '333333'],
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
        'particella' => '200',
        'subalterno' => '',
        'can_edit' => true,
        'owners' => [
            ['id' => 41, 'nome' => 'Empty', 'cognome' => 'Sub 2', 'codice_fiscale' => 'EMPTYSUB22222222', 'telefono' => '666666'],
        ],
    ],
];

$groups = group_properties_by_unit($properties);
$pass = true;
$errors = [];

if (count($groups) !== 3) {
    $pass = false;
    $errors[] = 'Attesi 3 gruppi, trovati ' . count($groups);
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
    if (abs((float) ($group['lat'] ?? 0) - 45.2) > 0.00001 || abs((float) ($group['lng'] ?? 0) - 9.2) > 0.00001) {
        $pass = false;
        $errors[] = 'La media coordinate del gruppo attesa è (45.2, 9.2)';
    }
}

$singleSubEmptyGroups = array_values(array_filter($groups, static fn (array $g): bool => (($g['subalterno'] ?? '') === '')));
if (count($singleSubEmptyGroups) !== 2) {
    $pass = false;
    $errors[] = 'I record con subalterno vuoto devono restare separati in 2 gruppi distinti';
}

if ($pass) {
    echo "PASS: grouped marker editor metadata OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: $error\n";
}
exit(1);
