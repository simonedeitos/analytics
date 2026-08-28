<?php

declare(strict_types=1);

/**
 * Test: più unità immobiliari con le stesse coordinate finiscono nello stesso marker logico.
 */

function group_by_coords(array $properties): array
{
    $groups = [];
    foreach ($properties as $property) {
        if (!isset($property['lat'], $property['lng'])) {
            continue;
        }
        $key = number_format((float) $property['lat'], 7, '.', '') . '|' . number_format((float) $property['lng'], 7, '.', '');
        $groups[$key][] = $property;
    }
    return $groups;
}

$properties = [
    ['id' => 1, 'lat' => 45.1234567, 'lng' => 10.1234567, 'subalterno' => '1'],
    ['id' => 2, 'lat' => 45.1234567, 'lng' => 10.1234567, 'subalterno' => '2'],
    ['id' => 3, 'lat' => 45.1230000, 'lng' => 10.1200000, 'subalterno' => '1'],
];

$groups = group_by_coords($properties);

$pass = true;
$errors = [];
if (count($groups) !== 2) {
    $pass = false;
    $errors[] = 'Attesi 2 marker logici, trovati ' . count($groups);
}

$firstKey = '45.1234567|10.1234567';
if (!isset($groups[$firstKey]) || count($groups[$firstKey]) !== 2) {
    $pass = false;
    $errors[] = 'Le 2 unità con coordinate identiche non sono state raggruppate insieme';
}

if ($pass) {
    echo "PASS: grouping coordinate condivise OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: {$error}\n";
}
exit(1);
