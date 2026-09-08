<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/gml_catalog.php';

$pass = true;
$errors = [];

$catalog = [
    'B157' => ['nome' => 'Brescia'],
    'B394' => ['nome' => 'Calcinato'],
    'D284' => ['nome' => 'Desenzano Del Garda'],
];

$matches = analyticspro_gml_search_comuni('cal', 12, $catalog);
if (count($matches) !== 1 || ($matches[0]['comune'] ?? '') !== 'Calcinato' || ($matches[0]['belfiore'] ?? '') !== 'B394') {
    $pass = false;
    $errors[] = 'Autocomplete comuni: match "cal" non corretto: ' . json_encode($matches);
}

$matchesNoSpaces = analyticspro_gml_search_comuni('desenzanodel', 12, $catalog);
if (count($matchesNoSpaces) !== 1 || ($matchesNoSpaces[0]['belfiore'] ?? '') !== 'D284') {
    $pass = false;
    $errors[] = 'Autocomplete comuni: match senza spazi non corretto: ' . json_encode($matchesNoSpaces);
}

$matchesEmpty = analyticspro_gml_search_comuni('', 12, $catalog);
if ($matchesEmpty !== []) {
    $pass = false;
    $errors[] = 'Autocomplete comuni: query vuota deve restituire array vuoto.';
}

if ($pass) {
    echo "PASS: ricerca comuni GML OK\n";
    exit(0);
}

foreach ($errors as $error) {
    echo "FAIL: {$error}\n";
}
exit(1);
