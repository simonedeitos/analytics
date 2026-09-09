<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/importer.php';

$pass = true;
$errors = [];

$cases = [
    ['label' => 'P.IVA numerica', 'input' => '12345678901', 'expected' => 'Società'],
    ['label' => 'CF persona maschio', 'input' => 'RSSMRA80A01H501U', 'expected' => 'M'],
    ['label' => 'CF persona femmina', 'input' => 'RSSMRA80A41H501U', 'expected' => 'F'],
    ['label' => 'Vuoto', 'input' => '', 'expected' => null],
    ['label' => 'Non valido', 'input' => 'ABC123', 'expected' => null],
];

foreach ($cases as $case) {
    $actual = analyticspro_guess_gender($case['input']);
    if ($actual !== $case['expected']) {
        $pass = false;
        $errors[] = $case['label'] . ': atteso ' . var_export($case['expected'], true) . ', ottenuto ' . var_export($actual, true);
    }
}

if ($pass) {
    echo "PASS: guess gender OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: {$error}\n";
}
exit(1);
