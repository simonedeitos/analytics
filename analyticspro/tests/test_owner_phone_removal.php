<?php

declare(strict_types=1);

require __DIR__ . '/../includes/functions.php';

$pass = true;
$errors = [];

$updated = analyticspro_remove_phone_value('111;222;333', '222');
if ($updated !== '111;333') {
    $pass = false;
    $errors[] = 'Rimozione intermedia non corretta: ' . json_encode($updated);
}

$single = analyticspro_remove_phone_value('222', '222');
if ($single !== null) {
    $pass = false;
    $errors[] = 'Rimozione ultimo numero non corretta: ' . json_encode($single);
}

$missing = analyticspro_remove_phone_value('111;222;333', '999');
if ($missing !== '111;222;333') {
    $pass = false;
    $errors[] = 'Numero non presente non deve alterare la stringa: ' . json_encode($missing);
}

$empty = analyticspro_remove_phone_value('', '222');
if ($empty !== null) {
    $pass = false;
    $errors[] = 'Stringa vuota deve restare nulla: ' . json_encode($empty);
}

if ($pass) {
    echo "PASS: rimozione telefoni multipli OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: {$error}\n";
}
exit(1);
