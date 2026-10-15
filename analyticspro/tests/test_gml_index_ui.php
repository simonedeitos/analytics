<?php

declare(strict_types=1);

// Eseguire con: php analyticspro/tests/test_gml_index_ui.php
$source = file_get_contents(dirname(__DIR__) . '/admin/import_gml.php');
foreach (['gml_index_build', 'gml_index_step', 'gml_inspect'] as $endpoint) {
    $api = file_get_contents(dirname(__DIR__) . '/api/admin/' . $endpoint . '.php');
    $guard = strpos($api, 'analyticspro_api_guard();');
    $bootstrap = strpos($api, "'/includes/bootstrap.php'");
    if ($guard === false || $bootstrap === false || $guard > $bootstrap) {
        throw new RuntimeException($endpoint . ': protezione JSON necessaria prima del bootstrap.');
    }
}
$start = strpos($source, "    document.getElementById('gml-inspect-btn')");
$end = strpos($source, '    // Delete comune', $start);
if ($start === false || $end === false) {
    throw new RuntimeException('Funzioni admin GML non trovate.');
}
$test = <<<'JS'
const assert = require('node:assert/strict');
const elements = new Map();
function element() {
    return {
        innerHTML: '', textContent: '', style: {}, children: [],
        addEventListener() {},
        appendChild(child) { this.children.push(child); },
        remove() {},
    };
}
const document = {
    getElementById(id) {
        if (!elements.has(id)) elements.set(id, element());
        return elements.get(id);
    },
    createElement: element,
};
const indexUrl = '/index', indexStepUrl = '/step', inspectUrl = '/inspect', csrf = 'test';
const escHtml = value => String(value).replace(/</g, '&lt;');
let catalogLoads = 0;
const loadCatalog = () => { catalogLoads++; };
let timers = new Map(), timerId = 0;
const setTimeout = (fn, delay) => { timers.set(++timerId, {fn, delay}); return timerId; };
const clearTimeout = id => timers.delete(id);
let status = 'queued', creates = 0, steps = 0, failedJob = false;
let releaseInitialPoll = null, polls = 0;
async function fetch(url, options = {}) {
    if (url.startsWith(inspectUrl)) {
        return {ok: false, status: 504, json: async () => { throw new Error('HTML gateway timeout'); }};
    }
    if (url === indexStepUrl) {
        steps++;
        if (steps === 1) throw new Error('Errore temporaneo di rete');
        if (steps === 2) return {ok: true, json: async () => ({ok: true, done: false, busy: true})};
        status = failedJob ? 'failed' : 'completed';
        if (releaseInitialPoll !== null) {
            releaseInitialPoll();
            releaseInitialPoll = null;
        }
        return {ok: true, json: async () => ({ok: true, done: true})};
    }
    if (options.method === 'POST') {
        creates++;
        return {ok: true, json: async () => ({ok: true, job_id: creates})};
    }
    const afterId = Number(new URL(url, 'http://localhost').searchParams.get('after_id'));
    const snapshot = {
        ok: true,
        job: {status, processed_comuni: status === 'queued' ? 0 : 1, total_comuni: 1,
            processed_particelle: status === 'queued' ? 0 : 3, total_particelle: 3,
            error_message: failedJob ? 'Un comune non indicizzato' : null},
        logs: [{id: 1, level: 'info', message: 'Job accodato'},
            ...(status === 'queued' ? [] : [{id: 2, level: 'info', message: 'Lavoro terminato'}])]
            .filter(log => log.id > afterId),
    };
    polls++;
    if (polls === 1) {
        return {ok: true, json: () => new Promise(resolve => { releaseInitialPoll = () => resolve(snapshot); })};
    }
    return {ok: true, json: async () => snapshot};
}
async function drain() {
    for (let i = 0; i < 80; i++) {
        await new Promise(resolve => setImmediate(resolve));
        const ready = [...timers].filter(([, timer]) => timer.delay < 45000);
        if (ready.length) {
            const [id, timer] = ready.sort((a, b) => a[1].delay - b[1].delay)[0];
            timers.delete(id);
            timer.fn();
        }
    }
}
JS;
$test .= "\n" . substr($source, $start, $end - $start) . "\n";
$test .= <<<'JS'
(async () => {
    buildIndex('ALL');
    buildIndex('B394');
    await drain();
    assert.equal(creates, 1, 'Doppio click ignorato');
    assert.equal(steps, 3, 'Retry temporaneo e busy non interrompono il lavoro');
    assert(polls >= 2, 'Il poll non terminale pendente non sostituisce la lettura finale');
    assert.equal(indexLogStatus.textContent, 'completed');
    assert.match(indexProgress.textContent, /Comuni: 1\/1 · Particelle: 3\/3/);
    assert.equal(indexLogBody.children.filter(line => line.textContent.includes('Job accodato')).length, 1);
    assert(indexLogBody.children.some(line => line.textContent.includes('Errore temporaneo')));
    assert.equal(catalogLoads, 1);

    failedJob = true;
    status = 'queued';
    buildIndex('B394');
    await drain();
    assert.equal(indexLogStatus.textContent, 'failed');
    assert.match(document.getElementById('gml-build-index-result').innerHTML, /Un comune non indicizzato/);

    inspectComune('');
    assert.match(document.getElementById('gml-inspect-result').innerHTML, /Belfiore valido/);
    inspectComune('B394');
    await drain();
    assert.match(document.getElementById('gml-inspect-result').innerHTML, /504/);
    console.log('PASS: UI GML step, retry, doppio click, log incrementali, contatori e diagnostica timeout');
})().catch(error => { console.error(error); process.exitCode = 1; });
JS;
$process = proc_open(['node'], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('Impossibile avviare Node per il test UI.');
}
fwrite($pipes[0], $test);
fclose($pipes[0]);
exit(proc_close($process));
