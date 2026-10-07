<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/report.php');
$source = (string) file_get_contents($root . '/assets/js/analyticspro.js');
$errors = [];

foreach ([
    "analyticspro_render_header('Proprietari'",
    "analyticspro_asset_url('assets/css/owners.css')",
    'analyticspro_require_auth()',
    "empty(\$subuserPermissions['can_view_reports'])",
    'report-export-actions',
    'Apri mappa',
    'report-search',
    'report-summary-properties',
    'report-summary-owners',
    'report-summary-phones',
    'report-filter-comune',
    'report-filter-foglio',
    'report-filter-particella',
    'report-filter-stato',
    'report-filter-assigned',
    'report-filter-color',
    'report-address-search',
    'report-filter-categoria',
    'Censimento e proprietari',
    'Tutti gli immobili e i contatti delle tue mappature',
    'Cerca per nome e cognome...',
    'owners-filter-row',
    'owners-cadastral-filter',
    'report-filters-toggle',
    'dropdown-menu dropdown-menu-end',
    'api/data/properties.php',
    'api/data/update_property.php',
    'api/data/delete_property.php',
] as $contract) {
    if (!str_contains($page, $contract)) {
        $errors[] = 'Contratto pagina mancante: ' . $contract;
    }
}
if (str_contains($page, 'Report in griglia')) {
    $errors[] = 'La pagina deve chiamarsi Proprietari.';
}

$script = 'const source = ' . json_encode($source, JSON_THROW_ON_ERROR) . ";\n" . <<<'JS'
const assert = require('node:assert/strict');
const vm = require('node:vm');
const elements = {};
let config;
let draws = 0;
const searches = {};
const table = {
    column(name) { return { search(value) { searches[name] = value; } }; },
    search(value) { searches.global = value; return this; },
    draw() { draws++; return this; },
    buttons() { return { container() { return { appendTo(element) { element.exportsMoved = true; } }; } }; },
};
const state = { tables: {}, role: 'tenant', canViewPhone: true };
const context = {
    state,
    document: { getElementById(id) { return elements[id] || null; } },
    $(selector) {
        return {
            html() {},
            DataTable(options) { config = options; return table; },
        };
    },
    STATE_OPTIONS: { '': 'Non impostato', da_contattare: 'Da contattare', contattato: 'Contattato', non_raggiungibile: 'Non Raggiungibile', interessato: 'Interessato' },
    escapeHtml(value) { return String(value).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;').replaceAll('"', '&quot;'); },
    ownerQuotaLabel(owner) { return owner.quota || ''; },
    ownerTitolaritaLabel(owner) { return owner.titolarita || ''; },
    formatDobWithAge(value) { return value; },
    buildAssignmentSummary() { return ''; },
    assignmentNamesLabel(property) { return (property.assignments || []).map(a => a.subuser_name).join(', ') || 'Non assegnato'; },
    detailColumn() { return '<button class="open-detail-modal">Dettaglio</button>'; },
    editableColumns(property) { return '<button class="open-editor-modal"' + (property.can_edit ? '' : ' disabled') + '>Modifica</button>'; },
    deleteColumns(property) { return property.can_delete ? '<button class="delete-property-btn">Elimina</button>' : '—'; },
    initReportFiltersToggle() {},
    closeReportContacts() {},
};
context.$.fn = { dataTable: { util: { escapeRegex: value => value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') } } };
vm.createContext(context);
for (const name of ['propertyCanViewPhone', 'splitPhoneNumbers', 'buildPhoneChips', 'buildOwnerSearchText',
    'buildOwnersTableHtml', 'unitLabel', 'buildTableData', 'initDataTable', 'reportStatusBadge', 'buildReportTableData',
    'ownerGroupKey', 'editorOwnerSelectionKey', 'buildReportContactsHtml',
    'updateReportSummary', 'applyReportFilters', 'renderReportTable', 'hydrateReportFilters']) {
    const start = source.indexOf('    function ' + name + '(');
    assert.notEqual(start, -1, 'Missing function ' + name);
    const end = source.indexOf('\n    }', start) + '\n    }'.length;
    vm.runInContext(source.slice(start, end), context);
}
const property = {
    id: 1, comune: '<Milano>', indirizzo: 'Via Roma', civico: '2', foglio: '10',
    particella: '42', subalterno: '1', categoria: 'A2', colore_marker: '#ff9900',
    stato: 'da_contattare', can_view_phone: true, can_edit: false, can_delete: true,
    assignments: [{ subuser_name: 'Agente' }],
    owners: [{ nome: 'Mario', cognome: 'Rossi', telefono: '123;456', email: '<mail@example.test>', quota: '1/2', luogo_nascita: 'Roma' }],
};
let row = context.buildReportTableData([property])[0];
assert.match(row.propertyHtml, /&lt;Milano&gt;/);
assert.match(row.propertyHtml, /F\.10 P\.42\/1/);
assert.match(row.propertyHtml, /owners-property-title.*Via Roma 2/);
assert.match(row.propertyHtml, /owners-property-dot.*owners-status--followup/);
assert.match(row.ownersHtml, /<strong>Rossi Mario<\/strong>/);
assert.match(row.owners, /1\/2/);
assert.match(row.owners, /Nato a: Roma/);
assert.doesNotMatch(row.owners, /copy-phone-btn/);
assert.match(row.contactsHtml, /report-contact-toggle/);
assert.doesNotMatch(row.contactsHtml, /123|456|mail@example/);
assert.match(context.buildReportContactsHtml(property), /copy-phone-btn/);
assert.match(context.buildReportContactsHtml(property), /&lt;mail@example.test&gt;/);
assert.match(row.contactsText, /123 456/);
assert.equal(row.stato, 'Da contattare');
assert.match(context.reportStatusBadge(row.stato, property), /owners-status--followup.*Da ricontattare/);
assert.match(context.reportStatusBadge('Contattato', { stato: 'contattato' }), /owners-status--contacted.*Contattato/);
assert.match(context.reportStatusBadge('Non Raggiungibile', { stato: 'non_raggiungibile' }), /owners-status--unreachable.*Non Raggiungibile/);
assert.match(context.reportStatusBadge('Non impostato', { stato: '' }), /owners-status--neutral.*Non contattato/);
assert.match(context.reportStatusBadge('Interessato', { stato: 'interessato' }), /Interessato/);
assert.match(context.reportStatusBadge('<Altro>', { stato: 'altro' }), /&lt;Altro&gt;/);
assert.equal(property.stato, 'da_contattare', 'Badge must not modify stored status');
assert.equal(row.assignmentsText, 'Agente');
assert.match(row.actionsHtml, /open-detail-modal/);
assert.match(row.actionsHtml, /open-editor-modal" disabled/);
assert.match(row.actionsHtml, /delete-property-btn/);
assert.match(row.actionsHtml, /<details class="owners-action-menu">/);
const multiOwner = { ...property, provincia: '<BS>', owners: [
    { nome: '<Mario>', cognome: 'Rossi', telefono: '3331234567', quota: '1/2' },
    { nome: 'Anna', cognome: 'Verdi', email: 'anna@example.test', quota: '1/2' },
] };
const multiRow = context.buildReportTableData([multiOwner])[0];
assert.match(multiRow.propertyHtml, /\(&lt;BS&gt;\)/);
assert.doesNotMatch(multiRow.ownersHtml, /<details|\+1 intestatario/);
assert.equal((multiRow.ownersHtml.match(/open-report-editor/g) || []).length, 2);
assert.match(multiRow.ownersHtml, /&lt;Mario&gt;/);
assert.match(multiRow.ownersHtml, /Verdi Anna/, 'All owners are visible without expanding');
assert.match(multiRow.ownersHtml, /data-owner-key="__idx_1"/);
assert.doesNotMatch(multiRow.contactsHtml, /333|•••/);
assert.match(context.buildReportContactsHtml(multiOwner), /data-phone="3331234567"/, 'Copy hook retains the complete phone');
assert.doesNotMatch(context.buildReportContactsHtml(multiOwner), /•••/);
assert.match(multiRow.contactsText, /3331234567/, 'Search and export retain complete contacts');
assert.match(context.buildReportTableData([{ ...property, owners: [], assignments: [] }])[0].contactsHtml, /bi-telephone-x.*|disabled/);
const restricted = { ...property, can_view_phone: false };
row = context.buildReportTableData([restricted])[0];
assert.doesNotMatch(row.contactsHtml, /123|456|copy-phone-btn/);
assert.doesNotMatch(row.contactsText, /123|456/);
assert.doesNotMatch(row.contactsHtml, /mail@example.test/);
assert.doesNotMatch(context.buildReportContactsHtml(restricted), /123|456|copy-phone-btn/);
assert.match(context.buildReportContactsHtml(restricted), /mail@example.test/);
elements['report-filter-categoria'] = { value: 'A2' };
state.properties = [property, { categoria: 'A20' }, { categoria: 'A2' }, { categoria: '<C1>' }];
context.hydrateReportFilters();
assert.equal(elements['report-filter-categoria'].value, 'A2');
assert.equal((elements['report-filter-categoria'].innerHTML.match(/value="A2"/g) || []).length, 1);
assert.match(elements['report-filter-categoria'].innerHTML, /value="&lt;C1&gt;"/);
state.properties = [{ categoria: 'C1' }];
context.hydrateReportFilters();
assert.equal(elements['report-filter-categoria'].value, '', 'Clear categories no longer in the dataset');
delete elements['report-filter-categoria'];
for (const key of ['properties', 'owners', 'phones']) elements['report-summary-' + key] = {};
context.updateReportSummary([property, restricted]);
assert.equal(elements['report-summary-properties'].textContent, '2');
assert.equal(elements['report-summary-owners'].textContent, '2');
assert.equal(elements['report-summary-phones'].textContent, '1');
context.updateReportSummary([restricted]);
assert.equal(elements['report-summary-phones'].textContent, 'Riservato');
context.updateReportSummary([]);
assert.equal(elements['report-summary-properties'].textContent, '0');
assert.equal(elements['report-summary-phones'].textContent, '0');
elements['report-export-actions'] = { innerHTML: 'old buttons' };
context.initDataTable('#report-table', context.buildReportTableData([property]), true, 'report');
assert.deepEqual(Array.from(config.columns.filter(c => c.visible !== false), c => c.title),
    ['Immobile', 'Proprietari', 'Contatti', 'Stato', 'Assegnato a', 'Dettaglio']);
assert.equal(config.pageLength, 75);
assert.equal(config.dom, 'Btlip');
assert.equal(config.buttons[0].extend, 'csvHtml5');
assert.equal(config.buttons[1].extend, 'excelHtml5');
for (const button of config.buttons) {
    assert.equal(button.exportOptions.orthogonal, 'export');
    const exported = Array.from(config.columns).filter((column, index) => button.exportOptions.columns(index));
    assert.deepEqual(exported.map(column => column.title),
        ['Proprietari', 'Contatti', 'Stato', 'Assegnato a', 'Colore', 'Comune', 'Foglio/Particella/Sub', 'Indirizzo', 'Categoria catastale']);
    assert.equal(exported.filter(column => column.data === 'unita').length, 1);
    assert.ok(exported.every(column => !['actionsHtml', 'detail', 'editor', 'deleteAction', 'foglioFilter', 'particellaFilter'].includes(column.data)));
}
const statusColumn = config.columns.find(column => column.name === 'stato');
const ownersColumn = config.columns.find(column => column.name === 'owners');
assert.equal(ownersColumn.render(multiRow.owners, 'display', multiRow), multiRow.ownersHtml);
assert.equal(ownersColumn.render(multiRow.owners, 'export', multiRow), multiRow.owners, 'Export keeps original owner information');
assert.equal(ownersColumn.render(multiRow.owners, 'sort', multiRow), multiRow.ownerSearch, 'Sorting is unchanged');
const assignmentColumn = config.columns.find(column => column.name === 'assigned');
assert.match(assignmentColumn.render(multiRow.assignmentsText, 'display', multiRow), /bi-person/);
assert.equal(assignmentColumn.render(multiRow.assignmentsText, 'export', multiRow), multiRow.assignmentsText);
assert.match(statusColumn.render('Da contattare', 'display', context.buildReportTableData([property])[0]), /Da ricontattare/);
assert.equal(statusColumn.render('Da contattare', 'filter', row), 'Da contattare');
assert.equal(statusColumn.render('Da contattare', 'export', row), 'Da contattare');
const colorColumn = config.columns.find(column => column.name === 'color');
assert.equal(colorColumn.render(row.colore, 'export', row), '#ff9900');
assert.equal(elements['report-export-actions'].exportsMoved, true);
for (const [id, value] of Object.entries({
    color: '#ff9900', comune: 'Milano', foglio: '10', particella: '42',
    categoria: 'A2', assigned: 'Agente', stato: 'Da contattare',
})) elements['report-filter-' + id] = { value };
elements['report-search'] = { value: 'Mario' };
elements['report-address-search'] = { value: 'Via Roma' };
context.applyReportFilters();
assert.equal(searches.global, '', 'No global multi-field search');
for (const [name, value] of Object.entries({
    color: '#ff9900', comune: 'Milano', foglioFilter: '10', particellaFilter: '42',
    categoriaFilter: '^A2$', owners: 'Mario', indirizzo: 'Via Roma', assigned: 'Agente', stato: 'Da contattare',
})) assert.equal(searches[name + ':name'], value);
assert.equal(draws, 1);
state.tables = {};
context.initDataTable('#report-table', [], false, 'report');
assert.equal(config.buttons.length, 0);
assert.equal(config.dom, 'tlip');
let page = 2;
let length = 50;
let order = [[1, 'asc']];
table.draw = function (mode) {
    if (mode !== 'page') page = 0;
    return table;
};
elements['report-table'] = {};
let bindings = 0;
elements['report-search'].addEventListener = () => { bindings++; };
elements['report-search'].dataset = {};
elements['report-address-search'].addEventListener = () => { bindings++; };
elements['report-address-search'].dataset = {};
state.properties = [property];
state.reportQuery = 'Mario';
state.tables['#report-table'] = table;
context.groupPropertiesByUnit = properties => properties;
context.hydrateReportFilters = () => {};
context.initDataTable = function () {
    page = 0;
    length = 75;
    order = [];
    state.tables['#report-table'] = table;
};
context.renderReportTable();
assert.equal(page, 0, 'Reload must retain the original first-page default');
assert.equal(length, 75, 'Reload must retain the original page-length default');
assert.deepEqual(order, [], 'Reload must retain the original unsorted default');
context.renderReportTable();
assert.equal(bindings, 2, 'Reload must not duplicate either search handler');
console.log('PASS: owners rendering, filters, exports, pagination and phone permissions OK');
JS;

$process = proc_open(['node'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
if (!is_resource($process)) {
    $errors[] = 'Impossibile avviare Node per verificare il rendering.';
} else {
    fwrite($pipes[0], $script);
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        $errors[] = 'Rendering JavaScript: ' . $output . $error;
    } else {
        echo $output;
    }
}
if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, "FAIL: {$error}\n");
    }
    exit(1);
}
echo "PASS: owners page smoke OK\n";
