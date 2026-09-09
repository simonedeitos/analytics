<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/functions.php';
define('ANALYTICSPRO_ROOT', dirname(__DIR__));
require_once dirname(__DIR__) . '/includes/property_repository.php';
require_once dirname(__DIR__) . '/includes/dashboard_stats.php';

$properties = [
    [
        'id' => 1,
        'provincia' => 'BS',
        'comune' => 'Brescia',
        'categoria' => 'A/2',
        'titolarita' => 'Piena proprietà',
        'lat' => 45.5,
        'lng' => 10.2,
        'created_at' => '2026-09-08 10:00:00',
        'owners' => [
            ['tipo' => 'persona', 'telefono' => '333123', 'email' => 'mario@example.com', 'genere' => 'M', 'data_nascita' => '1980-01-01'],
            ['tipo' => 'azienda', 'telefono' => '', 'email' => 'spa@example.com', 'genere' => 'Società', 'data_nascita' => null],
        ],
        'assignments' => [['subuser_id' => 20, 'subuser_name' => 'Mario Rossi']],
    ],
    [
        'id' => 2,
        'provincia' => 'BS',
        'comune' => 'Desenzano',
        'categoria' => 'C/6',
        'titolarita' => 'Usufrutto',
        'lat' => 45.4,
        'lng' => 10.5,
        'created_at' => '2026-09-07 09:00:00',
        'owners' => [
            ['tipo' => 'persona', 'telefono' => '', 'email' => '', 'genere' => 'F', 'data_nascita' => '1992-05-15'],
        ],
        'assignments' => [],
    ],
    [
        'id' => 3,
        'provincia' => 'BG',
        'comune' => 'Bergamo',
        'categoria' => 'A/3',
        'titolarita' => 'Piena proprietà',
        'lat' => null,
        'lng' => null,
        'created_at' => '2026-08-25 08:00:00',
        'owners' => [
            ['tipo' => 'persona', 'telefono' => '340999', 'email' => '', 'genere' => 'M', 'data_nascita' => '1950-03-20'],
        ],
        'assignments' => [['subuser_id' => 20, 'subuser_name' => 'Mario Rossi']],
    ],
];

$assignedProperties = [$properties[0], $properties[2]];
$bounds = analyticspro_dashboard_period_bounds('all', new DateTimeImmutable('2026-09-09 12:00:00'));
$stats = analyticspro_dashboard_build_stats($properties, $assignedProperties, [
    'bounds' => $bounds,
    'period' => 'all',
    'comune' => 'Brescia',
    'category' => '',
    'can_view_phone' => true,
    'can_view_analytics' => true,
    'is_subuser' => false,
    'map_limit' => 50,
]);

$pass = true;
$errors = [];

if (($stats['kpis']['properties']['value'] ?? null) !== 1) {
    $pass = false;
    $errors[] = 'properties KPI atteso 1 per comune Brescia.';
}
if (($stats['kpis']['owners']['value'] ?? null) !== 2) {
    $pass = false;
    $errors[] = 'owners KPI atteso 2 per comune Brescia.';
}
if (($stats['kpis']['phones']['value'] ?? null) !== 1) {
    $pass = false;
    $errors[] = 'phones KPI atteso 1 per comune Brescia.';
}
if (($stats['kpis']['assigned']['value'] ?? null) !== 1) {
    $pass = false;
    $errors[] = 'assigned KPI atteso 1 per user principale (solo immobili con assignments).';
}
if (($stats['series']['comune']['labels'][0] ?? '') !== 'Brescia') {
    $pass = false;
    $errors[] = 'Top comuni dovrebbe iniziare con Brescia.';
}
if (count($stats['map_points'] ?? []) !== 1) {
    $pass = false;
    $errors[] = 'La mini-mappa deve includere solo i punti del comune filtrato con coordinate.';
}
if (($stats['series']['comune_distribution']['labels'][0] ?? '') !== 'Brescia') {
    $pass = false;
    $errors[] = 'La distribuzione comuni deve includere Brescia come primo elemento nel filtro applicato.';
}
if (($stats['series']['contacts']['values'][0] ?? 0) < 1) {
    $pass = false;
    $errors[] = 'La serie contatti deve contenere almeno un proprietario con telefono.';
}

if ($pass) {
    echo "PASS: dashboard stats helper OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: {$error}\n";
}
exit(1);
