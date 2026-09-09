<?php

declare(strict_types=1);

require dirname(__DIR__) . '/includes/importer.php';

$errors = [];

$progress = analyticspro_enrichment_reconcile_progress_values(271, 10);
if ($progress['processed'] !== 261) {
    $errors[] = 'processed dovrebbe essere 261 quando restano 10 particelle su 271.';
}
if ($progress['total'] !== 271) {
    $errors[] = 'total dovrebbe restare 271 dopo la riconciliazione.';
}
if ($progress['processed'] > $progress['total']) {
    $errors[] = 'processed non deve mai superare total.';
}

$allUnresolved = analyticspro_enrichment_progress_payload(6, 0, 0, 6);
if ($allUnresolved['done'] !== true) {
    $errors[] = 'Quando non restano particelle eleggibili il chunk deve terminare subito.';
}
if ($allUnresolved['processed'] !== 6 || $allUnresolved['total'] !== 6) {
    $errors[] = 'Con 6 particelle irrisolvibili processed deve coincidere con total.';
}

$mixedProgress = analyticspro_enrichment_progress_payload(6, 3, 2, 1);
if ($mixedProgress['processed'] !== 3 || $mixedProgress['total'] !== 6) {
    $errors[] = 'processed deve essere resolved + unresolved senza superare total.';
}

$transition1 = analyticspro_enrichment_failure_transition(0, 3);
if ($transition1['next_attempts'] !== 1 || $transition1['mark_unresolved'] !== false) {
    $errors[] = 'Il primo tentativo fallito non deve marcare unresolved.';
}

$transition2 = analyticspro_enrichment_failure_transition(2, 3);
if ($transition2['next_attempts'] !== 3 || $transition2['mark_unresolved'] !== true) {
    $errors[] = 'Al terzo tentativo fallito la particella deve diventare unresolved.';
}

$stats = analyticspro_missing_coordinate_stats_normalize(0, 1, 0, 1);
if ($stats['total'] !== 0 || $stats['recoverable'] !== 0 || $stats['exhausted'] !== 0) {
    $errors[] = 'Un tenant senza immobili mancanti deve restituire tutti i contatori a zero.';
}

$report = analyticspro_enrichment_report_default();
analyticspro_enrichment_report_add_final_failure($report, [
    'comune' => 'Calcinato',
    'provincia' => 'BS',
    'foglio' => '12',
    'particella' => '345',
], 'comune_non_indicizzato', 'Indice GML assente', 'B394');
analyticspro_enrichment_report_add_final_failure($report, [
    'comune' => 'Calcinato',
    'provincia' => 'BS',
    'foglio' => '99',
    'particella' => '888',
], 'comune_non_indicizzato', 'Indice GML assente', 'B394');
if (count($report['missing_comuni']) !== 1) {
    $errors[] = 'I comuni non indicizzati devono comparire una sola volta nel report.';
}
if (($report['missing_comuni'][0]['belfiore'] ?? '') !== 'B394') {
    $errors[] = 'Il report deve esporre anche il codice Belfiore del comune non indicizzato.';
}

if ($errors === []) {
    echo "PASS: enrichment counters and unresolved transition OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: {$error}\n";
}

exit(1);
