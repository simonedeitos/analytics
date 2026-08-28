<?php

declare(strict_types=1);

/**
 * Test: riconciliazione assegnazioni.
 *
 * Partendo da [A,B] e inviando [B,C] il risultato deve essere [B,C]
 * (A rimosso, C aggiunto).
 *
 * Exit code: 0 = pass, 1 = fail.
 */

function reconcile_assignments(array $current, array $incoming): array
{
    $toAdd = array_diff($incoming, $current);
    $toRemove = array_diff($current, $incoming);
    $result = array_values(array_diff($current, $toRemove));
    foreach ($toAdd as $item) {
        $result[] = $item;
    }
    sort($result);
    return $result;
}

$pass = true;
$errors = [];

$result = reconcile_assignments(['A', 'B'], ['B', 'C']);
$expected = ['B', 'C'];
sort($result);
if ($result !== $expected) {
    $pass = false;
    $errors[] = 'Atteso [B,C], ottenuto ' . implode(',', $result);
}
if (in_array('A', $result, true)) {
    $pass = false;
    $errors[] = 'A non è stato rimosso';
}
if (!in_array('C', $result, true)) {
    $pass = false;
    $errors[] = 'C non è stato aggiunto';
}
if (!in_array('B', $result, true)) {
    $pass = false;
    $errors[] = 'B non è rimasto';
}

if ($pass) {
    echo "PASS: riconciliazione assegnazioni OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: $error\n";
}
exit(1);
