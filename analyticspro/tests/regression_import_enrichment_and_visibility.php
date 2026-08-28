<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/property_repository.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$repoRoot = dirname(__DIR__);
$enrichChunk = file_get_contents($repoRoot . '/api/data/enrich_chunk.php') ?: '';
$importer = file_get_contents($repoRoot . '/includes/importer.php') ?: '';
$propertyRepository = file_get_contents($repoRoot . '/includes/property_repository.php') ?: '';
$js = file_get_contents($repoRoot . '/assets/js/analyticspro.js') ?: '';

// 1) enrich_chunk route regressione: usa batch_id=0 con scope_batch_id per flusso import.
assert_true(str_contains($enrichChunk, "scope_batch_id"), 'enrich_chunk deve supportare scope_batch_id');
assert_true(str_contains($enrichChunk, 'analyticspro_enrich_batch_coordinates_chunk(0, $limit,'), 'enrich_chunk deve usare il percorso chunk globale con scope batch');

// 2) fallback colonne opzionali: enrichment_report mancante non deve rompere chunk.
assert_true(str_contains($importer, 'UPDATE import_batches SET enrichment_processed = enrichment_processed + :delta WHERE id = :id'), 'fallback update senza enrichment_report mancante');
assert_true(str_contains($importer, 'SELECT enrichment_processed, enrichment_total FROM import_batches WHERE id = :id'), 'fallback select senza enrichment_report mancante');

// 3) visibilità assegnati per ruolo.
[$joinsMain, $whereMain, $paramsMain] = analyticspro_visible_property_scope(['role' => 'user', 'id' => 10], 'assigned', null, 'all');
assert_true($joinsMain === [], 'utente principale in filtro "all" deve vedere anche non assegnati');
assert_true(!isset($paramsMain['assignment_subuser']), 'utente principale senza filtro subutente non deve limitare le assegnazioni');

[$joinsSub, $whereSub, $paramsSub] = analyticspro_visible_property_scope(['role' => 'subuser', 'parent_user_id' => 10, 'id' => 33], 'assigned', null, 'all');
assert_true(in_array('INNER JOIN property_assignments pa_filter ON pa_filter.property_id = p.id', $joinsSub, true), 'subutente deve usare join su assegnazioni');
assert_true(($paramsSub['assignment_subuser'] ?? null) === 33, 'subutente deve vedere solo i marker assegnati a sé');

[$joinsUnassigned, $whereUnassigned] = analyticspro_visible_property_scope(['role' => 'user', 'id' => 10], 'assigned', null, 'unassigned');
assert_true(in_array('NOT EXISTS (SELECT 1 FROM property_assignments pa_filter WHERE pa_filter.property_id = p.id)', $whereUnassigned, true), 'filtro non assegnati deve essere disponibile');

// 4) permesso telefono OFF: campo telefono aggiunto solo condizionalmente.
assert_true(str_contains($propertyRepository, 'if ($showPhone) {'), 'payload owners deve gestire telefono con guardia esplicita');
assert_true(str_contains($propertyRepository, "'telefono' =" ) === false, 'nessun assegnamento non condizionato al campo telefono');

// 5) retry chunk limitato e raggruppamento per immobile.
assert_true(str_contains($js, 'let consecutiveFailures = 0;'), 'enrichChunkLoop deve tracciare errori consecutivi');
assert_true(str_contains($js, 'if (consecutiveFailures >= 3)'), 'enrichChunkLoop deve fermarsi dopo 3 errori consecutivi');
assert_true(str_contains($js, 'return properties.map(property => ({'), 'report/assegnati devono restare raggruppati per immobile (1 riga per property)');

echo "OK: regression_import_enrichment_and_visibility\n";
exit(0);
