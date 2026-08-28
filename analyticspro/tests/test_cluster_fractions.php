<?php

declare(strict_types=1);

/**
 * Test: calcolo fette cluster a torta.
 *
 * 10 marker con 5 blu / 3 rossi / 2 verdi → percentuali 50/30/20.
 *
 * Exit code: 0 = pass, 1 = fail.
 */

function compute_cluster_fractions(array $markers): array
{
    $total = count($markers);
    if ($total === 0) {
        return [];
    }
    $counts = [];
    foreach ($markers as $marker) {
        $color = $marker['color'] ?? '#000000';
        $counts[$color] = ($counts[$color] ?? 0) + 1;
    }
    $fractions = [];
    foreach ($counts as $color => $count) {
        $fractions[] = [
            'color' => $color,
            'count' => $count,
            'percentage' => round(($count / $total) * 100),
        ];
    }
    usort($fractions, fn (array $a, array $b): int => $b['count'] <=> $a['count']);
    return $fractions;
}

$markers = array_merge(
    array_fill(0, 5, ['color' => '#0000ff']),
    array_fill(0, 3, ['color' => '#ff0000']),
    array_fill(0, 2, ['color' => '#00ff00'])
);

$fractions = compute_cluster_fractions($markers);

$pass = true;
$errors = [];

if (count($fractions) !== 3) {
    $pass = false;
    $errors[] = 'Attese 3 fette, trovate ' . count($fractions);
}

$byColor = [];
foreach ($fractions as $fraction) {
    $byColor[$fraction['color']] = $fraction;
}

$expected = [
    '#0000ff' => 50,
    '#ff0000' => 30,
    '#00ff00' => 20,
];
foreach ($expected as $color => $pct) {
    if (!isset($byColor[$color])) {
        $pass = false;
        $errors[] = "Colore $color mancante";
    } elseif ((int) $byColor[$color]['percentage'] !== $pct) {
        $pass = false;
        $errors[] = 'Colore ' . $color . ': atteso ' . $pct . '%, trovato ' . $byColor[$color]['percentage'] . '%';
    }
}

if ($pass) {
    echo "PASS: cluster fractions OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: $error\n";
}
exit(1);
