<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

$pass = true;
$errors = [];

$states = analyticspro_state_options();
if (($states['non_raggiungibile'] ?? '') !== 'Non Raggiungibile') {
    $pass = false;
    $errors[] = 'Stato non_raggiungibile non presente nelle opzioni.';
}

$colors = analyticspro_state_colors();
if (($colors['non_raggiungibile'] ?? '') !== '#6c757d') {
    $pass = false;
    $errors[] = 'Colore predefinito stato non_raggiungibile non corretto.';
}

$fixedDate = new DateTimeImmutable('2026-02-15 14:45:33');
$stateLog = analyticspro_note_log_state_change('Da Contattare', $fixedDate);
if ($stateLog !== '[15/02/2026 14:45] - Cambio stato: Da Contattare') {
    $pass = false;
    $errors[] = 'Formato log cambio stato non corretto: ' . $stateLog;
}

$manualLog = analyticspro_note_log_manual_note('ho chiamato non risponde', $fixedDate);
if ($manualLog !== '[15/02/2026 14:45] - Nota: ho chiamato non risponde') {
    $pass = false;
    $errors[] = 'Formato log nota manuale non corretto: ' . $manualLog;
}

if ($pass) {
    echo "PASS: stati e log note OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: {$error}\n";
}
exit(1);
