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

$transition1 = analyticspro_enrichment_failure_transition(0, 3);
if ($transition1['next_attempts'] !== 1 || $transition1['mark_unresolved'] !== false) {
    $errors[] = 'Il primo tentativo fallito non deve marcare unresolved.';
}

$transition2 = analyticspro_enrichment_failure_transition(2, 3);
if ($transition2['next_attempts'] !== 3 || $transition2['mark_unresolved'] !== true) {
    $errors[] = 'Al terzo tentativo fallito la particella deve diventare unresolved.';
}

if ($errors === []) {
    echo "PASS: enrichment counters and unresolved transition OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: {$error}\n";
}

exit(1);
