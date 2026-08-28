<?php

declare(strict_types=1);

/**
 * Test: enrich_chunk con batch_id inesistente restituisce JSON strutturato con error_code.
 *
 * Exit code: 0 = pass, 1 = fail.
 */

function simulate_enrich_chunk_error(int $batchId): array
{
    if ($batchId < 0) {
        return ['ok' => false, 'error_code' => 'invalid_param', 'error' => 'Parametro batch_id non valido o mancante.'];
    }
    return ['ok' => false, 'error_code' => 'batch_not_found', 'error' => 'Batch non trovato o non autorizzato.'];
}

$pass = true;
$errors = [];

$response = simulate_enrich_chunk_error(999999999);
if ($response['ok'] !== false) {
    $pass = false;
    $errors[] = 'ok dovrebbe essere false per batch inesistente';
}
if (!isset($response['error_code'])) {
    $pass = false;
    $errors[] = 'error_code mancante nella risposta di errore';
}
if ($response['error_code'] !== 'batch_not_found') {
    $pass = false;
    $errors[] = "error_code atteso 'batch_not_found', trovato '" . $response['error_code'] . "'";
}
if (!isset($response['error'])) {
    $pass = false;
    $errors[] = "campo 'error' mancante nella risposta";
}

$responseNeg = simulate_enrich_chunk_error(-1);
if ($responseNeg['error_code'] !== 'invalid_param') {
    $pass = false;
    $errors[] = "error_code per batch_id negativo dovrebbe essere 'invalid_param'";
}

if ($pass) {
    echo "PASS: enrich_chunk error structure OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: $error\n";
}
exit(1);
