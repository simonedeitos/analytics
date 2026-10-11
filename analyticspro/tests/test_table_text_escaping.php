<?php

declare(strict_types=1);

$source = file_get_contents(dirname(__DIR__) . '/assets/js/analyticspro.js');
$functions = '';
foreach (['escapeHtml', 'renderTableText'] as $name) {
    if (!preg_match('/    function ' . $name . '\(.*?(?=\n    (?:async )?function )/s', $source, $match)) {
        throw new RuntimeException('Missing renderer: ' . $name);
    }
    $functions .= $match[0] . "\n";
}
if (!preg_match('/columns\.forEach\(function \(column\) \{.*?\n        \}\);/s', $source, $match)) {
    throw new RuntimeException('All table contexts must apply text rendering.');
}
$configure = $match[0];
$test = <<<'JS'
const assert = require('node:assert/strict');
const columns = ['tenant', 'provincia', 'comune', 'indirizzo', 'unita', 'stato', 'foglioFilter', 'particellaFilter', 'categoriaFilter', 'owners', 'editor'].map(data => ({data}));
const badgeRenderer = () => '<span>Stato</span>';
columns.push({data: 'stato', render: badgeRenderer});
JS;
$test .= "\n" . $functions . "\n" . $configure . "\n";
$test .= <<<'JS'
for (const column of columns) {
    if (column.render === badgeRenderer) {
        assert.equal(column.render(), '<span>Stato</span>', 'Preserve existing safe HTML renderers');
    } else if (['owners', 'editor'].includes(column.data)) {
        assert.equal(column.render, undefined, 'Intentional HTML remains unchanged');
    } else {
        assert.equal(column.render, renderTableText);
    }
}
for (const [raw, escaped] of [
    ['Via S. Anna, 12/A', 'Via S. Anna, 12/A'],
    ["Via dell'Anna & figli", 'Via dell&#39;Anna &amp; figli'],
    ['Via &amp;', 'Via &amp;amp;'],
    ['<img src=x onerror="alert(1)">', '&lt;img src=x onerror=&quot;alert(1)&quot;&gt;'],
    ['<script>alert(1)</script>', '&lt;script&gt;alert(1)&lt;/script&gt;']
]) {
    assert.equal(renderTableText(raw, 'display'), escaped);
    for (const type of ['sort', 'filter', 'export', 'type']) assert.equal(renderTableText(raw, type), raw);
}
assert.equal(renderTableText(null, 'display'), '');
console.log('PASS: table text escaping preserves raw values and intentional HTML');
JS;
$process = proc_open(['node'], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('Unable to start Node test runner.');
}
fwrite($pipes[0], $test);
fclose($pipes[0]);
exit(proc_close($process));
