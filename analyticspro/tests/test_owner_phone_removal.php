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

$missingComma = analyticspro_remove_phone_value('111,222', '999');
if ($missingComma !== '111,222') {
    $pass = false;
    $errors[] = 'Numero non presente non deve alterare il formato originale: ' . json_encode($missingComma);
}

$missingDup = analyticspro_remove_phone_value('111;111;222', '999');
if ($missingDup !== '111;111;222') {
    $pass = false;
    $errors[] = 'Numero non presente non deve deduplicare i dati esistenti: ' . json_encode($missingDup);
}

$removeDup = analyticspro_remove_phone_value('111;111;222', '111');
if ($removeDup !== '222') {
    $pass = false;
    $errors[] = 'Rimozione duplicati presenti non corretta: ' . json_encode($removeDup);
}

$mixedRaw = ' 111 ; 222, 333 ';
$mixedList = analyticspro_split_phone_values($mixedRaw);
if ($mixedList !== ['111', '222', '333']) {
    $pass = false;
    $errors[] = 'Split telefoni con separatori misti non corretto: ' . json_encode($mixedList);
}
if (!in_array('222', $mixedList, true)) {
    $pass = false;
    $errors[] = 'Verifica presenza numero nel percorso endpoint non corretta';
}
$mixedRemoved = analyticspro_remove_phone_value($mixedRaw, '222');
if ($mixedRemoved !== '111;333') {
    $pass = false;
    $errors[] = 'Rimozione su separatori misti non corretta: ' . json_encode($mixedRemoved);
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
