<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$page = (string) file_get_contents($root . '/report.php');
$source = (string) file_get_contents($root . '/assets/js/analyticspro.js');
$styles = (string) file_get_contents($root . '/assets/css/owners.css');
$dom = new DOMDocument();
libxml_use_internal_errors(true);
$dom->loadHTML(preg_replace('/<\?[\s\S]*?\?>/', '', substr($page, strpos($page, '<div id="analyticspro-app"'))));
$xpath = new DOMXPath($dom);
foreach (['comune', 'foglio', 'particella', 'stato', 'assigned', 'color', 'categoria'] as $filter) {
    if ($xpath->query('//*[@id="report-filters"]//*[@id="report-filter-' . $filter . '"]')->length !== 1) {
        throw new RuntimeException('All secondary filters must be inside the toggled row: ' . $filter);
    }
}
if ($xpath->query('//select[@id="report-filter-categoria"]')->length !== 1
    || $xpath->query('//*[@id="report-filters"]//*[@id="report-table"]')->length !== 0
    || $xpath->query('//*[@id="report-filters"]//*[@id="report-search" or @id="report-address-search"]')->length !== 0
    || $xpath->query('//*[@id="report-filters"][@hidden]')->length !== 1
    || $xpath->query('//*[@id="report-filters-reset"]')->length !== 1
    || $xpath->query('//*[@id="report-filters-toggle"][@aria-expanded="false"]')->length !== 1
    || !str_contains($page, 'bi-funnel')
    || !str_contains($page, 'class="analyticspro-map-field owners-filter-field"')
    || !str_contains($styles, 'grid-template-columns: minmax(100px, 1fr)')
    || !str_contains($styles, '.owners-filter-field .form-select')
    || !str_contains($page, "analyticspro_asset_url('assets/css/map.css')")) {
    throw new RuntimeException('Compact collapsed filters, visible search controls, reset action and shared map field styles are required.');
}

$script = 'const source = ' . json_encode($source, JSON_THROW_ON_ERROR) . ";\n" . <<<'JS'
const assert = require('node:assert/strict');
const vm = require('node:vm');
const nodes = {};
const handlers = {};
const state = { canViewPhone: true, role: 'tenant' };
const storage = { value: null, getItem() { return this.value; }, setItem(key, value) { this.value = value; } };
let savedOwnerKey, presented, opened, readOnly, filterApplications = 0;
function node(id) {
    return nodes[id] = {
        value: '', dataset: {}, innerHTML: '', hidden: false, attributes: {},
        classList: { toggle() {} },
        setAttribute(name, value) { this.attributes[name] = value; },
        addEventListener(name, fn) { this[name] = fn; },
    };
}
const property = {
    id: 1, can_edit: true, can_view_phone: true, comune: 'Milano', indirizzo: 'Via Roma',
    stato: '', owners: [{ id: 4, nome: 'Anna', _groupOwnerKey: 'owner-a', _canEdit: true },
        { id: 5, nome: 'Bruno', _groupOwnerKey: 'owner-b', _canEdit: false }]
};
const context = {
    state, localStorage: storage,
    applyReportFilters() { filterApplications++; },
    document: {
        getElementById(id) { return nodes[id] || null; },
        createElement() { return { innerHTML: '' }; },
        addEventListener(name, fn) { handlers[name] = fn; },
    },
    bootstrap: {
        Popover: class {
            constructor(button, options) { this.button = button; this.options = options; }
            show() { this.shown = true; }
            dispose() { this.disposed = true; }
        },
        Modal: { getOrCreateInstance() { return { show() { opened = true; } }; } }
    },
    findPropertyGroupById() { return property; },
    buildReportContactsHtml(p) { return p.can_view_phone ? '<div>3331234567</div>' : ''; },
    ensureSharedModals() {},
    setModalError() {},
    unitLabel() { return 'F.1 P.2'; },
    buildSelectOptions() { return '<option>Stato</option>'; },
    MARKER_COLOR_PALETTE: [{ value: '#fff' }],
    defaultColorForState() { return '#fff'; },
    colorPaletteOptions() { return '<option>Colore</option>'; },
    updateEditorColorPreview() {},
    updateColorSelectAppearance() {},
    updateReportFilterColorPreview() {},
    updateMapEditorPresentation(p) { presented = p; },
    renderEditorOwnerSelect(p, key) { savedOwnerKey = key; },
    renderEditorOwners(p, key) { assert.equal(key, savedOwnerKey); },
    renderEditorNotesLog() {},
    refreshEditorAssignmentSummary() {},
    openDetailModal(id, key) { readOnly = [id, key]; },
    alert(message) { throw new Error(message); },
};
vm.createContext(context);
for (const name of ['propertyCanViewPhone', 'ownerCanEdit', 'ownerGroupKey', 'editorOwnerSelectionKey',
    'closeReportContacts', 'toggleReportContacts', 'initReportFiltersToggle', 'openReportEditor', 'openEditorModal']) {
    const start = source.indexOf('    function ' + name + '(');
    assert.notEqual(start, -1);
    const end = source.indexOf('\n    }', start) + '\n    }'.length;
    vm.runInContext(source.slice(start, end), context);
}
const toggle = node('report-filters-toggle');
const filters = node('report-filters');
const reset = node('report-filters-reset');
const resettableFilters = ['comune', 'foglio', 'particella', 'stato', 'assigned', 'color', 'categoria']
    .map(name => node('report-filter-' + name));
context.initReportFiltersToggle();
assert.equal(filters.hidden, true);
assert.equal(toggle.attributes['aria-expanded'], 'false');
assert.equal(toggle.attributes['aria-label'], 'Mostra filtri');
assert.equal(toggle.title, 'Mostra filtri');
toggle.click();
assert.equal(filters.hidden, false);
assert.equal(storage.value, '1');
assert.equal(toggle.attributes['aria-expanded'], 'true');
assert.equal(toggle.attributes['aria-label'], 'Nascondi filtri');
const click = toggle.click;
context.initReportFiltersToggle();
assert.equal(toggle.click, click, 'Initialization is idempotent');
delete toggle.dataset.reportBound;
storage.getItem = () => { throw new Error('Storage unavailable'); };
storage.setItem = () => { throw new Error('Storage unavailable'); };
context.initReportFiltersToggle();
toggle.click();
assert.equal(filters.hidden, false, 'Toggle works even when browser storage is blocked');
resettableFilters.forEach(control => { control.value = 'active'; });
reset.click();
assert.ok(resettableFilters.every(control => control.value === ''), 'Reset clears the advanced filters');
assert.equal(filterApplications, 1, 'Reset reapplies the existing filter logic');

const contact = { dataset: { propertyId: '1' }, isConnected: true, focus() { this.focused = true; } };
context.toggleReportContacts(contact);
let popover = state.reportContactPopover;
assert.equal(popover.shown, true);
assert.equal(popover.options.container, 'body', 'Overlay escapes table overflow');
assert.equal(popover.options.trigger, 'manual');
assert.match(popover.options.content.innerHTML, /3331234567/);
context.toggleReportContacts(contact);
assert.equal(popover.disposed, true);
assert.equal(state.reportContactPopover, null, 'Second click closes the popover');
context.toggleReportContacts(contact);
handlers.keydown({ key: 'Escape' });
assert.equal(state.reportContactPopover, null);
assert.equal(contact.focused, true);
property.can_view_phone = false;
context.toggleReportContacts(contact);
assert.equal(state.reportContactPopover, null, 'Do not open empty restricted contacts');

for (const id of ['report-table', 'property-editor-modal', 'property-editor-meta', 'editor-state',
    'editor-color', 'editor-custom-state', 'editor-note', 'editor-save-btn', 'editor-assignments-open']) node(id);
context.openReportEditor(1, 'owner-a');
assert.equal(opened, true, 'Owner click opens the editor immediately');
assert.equal(savedOwnerKey, 'owner-a', 'Selected grouped owner is passed to the existing editor');
assert.equal(presented, property, 'Report uses the same drawer presentation as the map');
assert.equal(nodes['editor-save-btn'].dataset.propertyId, '1', 'Existing save hook is unchanged');
opened = false;
context.openReportEditor(1, 'owner-b');
assert.deepEqual(readOnly, [1, 'owner-b']);
assert.equal(opened, false, 'Read-only owner cannot acquire editing rights from another grouped owner');
property.can_edit = false;
context.openReportEditor(1);
assert.deepEqual(readOnly, [1, undefined]);
property.can_edit = true;
context.openReportEditor(1);
assert.equal(savedOwnerKey, 'all', 'Dettaglio opens editable fields for the full group');
assert.match(source, /detailBtn\.closest\('#report-table'\)\) openReportEditor/);
assert.match(source, /if \(!t\.closest\('\.owners-contact-popover'\)\) closeReportContacts\(\)/);
assert.match(source, /getElementById\('report-table'\)\) prepareMapEditorDrawer\(\)/);
console.log('PASS: owners UX markup, persistent filters, contact overlay and direct owner editor');
JS;

$process = proc_open(['node'], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('Unable to start Node test runner.');
}
fwrite($pipes[0], $script);
fclose($pipes[0]);
exit(proc_close($process));
