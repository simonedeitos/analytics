<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../assets/js/analyticspro.js');
$functions = '';
foreach ([
    'escapeHtml', 'propertyCanViewPhone', 'ownerCanEdit', 'ownerGroupKey',
    'quotaLabel', 'ownerQuotaLabel', 'ownerTitolaritaLabel', 'splitPhoneNumbers',
    'buildEditablePhoneChips', 'groupPropertiesByUnit', 'editorOwnerSelectionKey',
    'resolveEditorOwnerSelection', 'buildEditorOwnersHtml', 'addOwnerPhone',
    'removeOwnerPhone', 'applyOwnerPhoneUpdate',
] as $name) {
    preg_match('/    (?:async )?function ' . $name . '\(.*?(?=\n    (?:async )?function )/s', $source, $match);
    if (!$match) {
        throw new RuntimeException('Funzione non trovata: ' . $name);
    }
    $functions .= $match[0] . "\n";
}

$test = <<<'JS'
const assert = require('node:assert/strict');
const state = { canViewPhone: true, propertyUpdateEndpoint: '/update', csrfToken: 'test', properties: [] };
const groupCanonicalRecords = records => [records];
const updateKpis = () => {};
const renderAssignedTable = () => {};
const renderReportTable = () => {};
const renderCharts = () => {};
let refreshedId;
const refreshOpenPropertyViews = id => { refreshedId = id; };
let request;
const api = async (url, options) => {
    request = { url, method: options.method, payload: JSON.parse(options.body) };
    return { ok: true, telefono: request.payload.action === 'add_owner_phone' ? '111;222;333' : '111;333' };
};
JS;
$test .= "\n" . $functions . "\n";
$test .= <<<'JS'
(async () => {
    state.properties = [
        { id: 10, can_edit: true, can_view_phone: true, owners: [
            { id: 101, nome: 'Alice', telefono: '111;222' },
            { id: 102, nome: 'Bruno', telefono: '' },
            { id: 0, nome: 'Senza ID', telefono: '444' }
        ] },
        { id: 20, can_edit: false, can_view_phone: true, owners: [
            { id: 201, nome: 'Carla', telefono: '555' }
        ] }
    ];
    const group = () => groupPropertiesByUnit(state.properties)[0];
    const count = (html, className) => (html.match(new RegExp(className, 'g')) || []).length;
    const html = buildEditorOwnersHtml(group(), 'all');
    assert.equal(count(html, 'add-owner-phone-toggle-btn'), 2, 'Aggiunta per ogni intestatario modificabile, anche senza telefoni');
    assert.equal(count(html, 'remove-owner-phone-btn'), 2, 'Eliminazione per ogni numero del contatto modificabile');
    assert.ok(html.includes('data-property-id="10" data-owner-id="101"'));
    assert.ok(html.includes('data-property-id="10" data-owner-id="102"'));
    assert.ok(html.includes('555'), 'I contatti in sola lettura rimangono visibili');
    assert.ok(!html.includes('data-owner-id="201"'), 'Nessun controllo per contatti non modificabili');
    assert.ok(!html.includes('data-owner-id="0"'), 'Nessun controllo senza ID persistito');
    const readOnlySelected = buildEditorOwnersHtml(group(), 'ID:201');
    assert.equal(count(readOnlySelected, 'add-owner-phone-toggle-btn'), 0);
    assert.equal(count(readOnlySelected, 'remove-owner-phone-btn'), 0);
    state.properties[1].can_edit = true;
    const editableGroup = buildEditorOwnersHtml(group(), 'all');
    assert.ok(editableGroup.includes('data-property-id="20" data-owner-id="201"'), 'Usare la property sorgente anche per intestatari non primari');
    assert.equal(count(editableGroup, 'add-owner-phone-toggle-btn'), 3);
    state.properties[1].can_edit = false;

    const selected = buildEditorOwnersHtml(group(), 'ID:101');
    assert.equal(count(selected, 'add-owner-phone-toggle-btn'), 1);
    assert.ok(!selected.includes('Bruno'));
    assert.ok(!selected.includes('Carla'));
    const single = buildEditorOwnersHtml({ id: 10, can_edit: true, owners: [{ id: 101, telefono: '111' }] }, 'all');
    assert.equal(count(single, 'remove-owner-phone-btn'), 1, 'Preservare modifica con un solo intestatario');
    const readOnly = buildEditorOwnersHtml({ id: 10, can_edit: false, owners: [{ id: 101, telefono: '111' }] }, 'all');
    assert.equal(count(readOnly, 'add-owner-phone-toggle-btn'), 0);
    assert.equal(count(readOnly, 'remove-owner-phone-btn'), 0);
    const hidden = buildEditorOwnersHtml(Object.assign(group(), { can_view_phone: false }), 'all');
    assert.ok(!hidden.includes('111'));
    assert.equal(count(hidden, 'add-owner-phone-toggle-btn'), 0);
    assert.equal(count(hidden, 'remove-owner-phone-btn'), 0);
    assert.equal(count(buildEditorOwnersHtml({ owners: [] }, 'all'), 'add-owner-phone-toggle-btn'), 0);
    const escaped = buildEditablePhoneChips('"><script>;222;222', 10, 101, true);
    assert.ok(!escaped.includes('<script>'));
    assert.equal(count(escaped, 'remove-owner-phone-btn'), 2, 'Non duplicare i controlli per numeri ripetuti');

    const added = await addOwnerPhone(10, 101, '333');
    assert.deepEqual(request, { url: '/update', method: 'POST', payload: {
        csrf_token: 'test', action: 'add_owner_phone', property_id: 10, owner_id: 101, phone: '333'
    } });
    applyOwnerPhoneUpdate(10, 101, added.telefono);
    assert.equal(refreshedId, 10);
    assert.equal(count(buildEditorOwnersHtml(group(), 'all'), 'remove-owner-phone-btn'), 3);
    const removed = await removeOwnerPhone(10, 101, '222');
    assert.equal(request.payload.action, 'remove_owner_phone');
    assert.equal(request.payload.property_id, 10);
    assert.equal(request.payload.owner_id, 101);
    assert.equal(request.payload.phone, '222');
    applyOwnerPhoneUpdate(10, 101, removed.telefono);
    assert.equal(state.properties[0].owners[0].telefono, '111;333', 'Aggiornare la cache usata alla riapertura del marker');
    assert.equal(state.properties[1].owners[0].telefono, '555', 'Non modificare gli altri contatti');
    assert.equal(count(buildEditorOwnersHtml(group(), 'all'), 'remove-owner-phone-btn'), 2);
    applyOwnerPhoneUpdate(10, 101, '');
    const empty = buildEditorOwnersHtml(group(), 'all');
    assert.equal(count(empty, 'remove-owner-phone-btn'), 0);
    assert.equal(count(empty, 'add-owner-phone-toggle-btn'), 2, 'Aggiunta disponibile dopo la rimozione dell’ultimo numero');
    console.log('PASS: marker phone controls, owner permissions, API payloads and cache updates');
})().catch(error => { console.error(error); process.exitCode = 1; });
JS;

$tmp = tempnam(sys_get_temp_dir(), 'analyticspro-phone-test-');
try {
    file_put_contents($tmp, $test);
    passthru('node ' . escapeshellarg($tmp), $exitCode);
} finally {
    unlink($tmp);
}
exit($exitCode);
