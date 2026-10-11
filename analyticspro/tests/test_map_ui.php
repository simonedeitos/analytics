<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../assets/js/analyticspro.js');
$page = file_get_contents(__DIR__ . '/../mappa.php');
$css = file_get_contents(__DIR__ . '/../assets/css/map.css');
foreach ([
    '/    function createMapMarker\([\s\S]*?(?=\n    (?:async )?function )/' => 'e1cced27fb843eadf84d340cce6491111d2521b5d1077aa5f44beaa079c33bb1',
] as $pattern => $hash) {
    if (!preg_match($pattern, $source, $match) || hash('sha256', $match[0]) !== $hash) {
        throw new RuntimeException('Cluster creation/options/events and marker rendering must remain unchanged.');
    }
}
if (!preg_match('/state\.markers = L\.markerClusterGroup\(\{[\s\S]*?spiderfyDistanceMultiplier: 1\.8/', $source)) {
    throw new RuntimeException('Spiderfied markers must have additional spacing.');
}
if (!str_contains($source, '_spiderfyHiddenLayers: []')
    || !preg_match('/            var activeSpiderfyEvent = null;[\s\S]*?(?=            state\.map\.addLayer\(state\.markers\);)/', $source, $spiderfyHandlers)
    || preg_match('/state\.markers\.(?:removeLayer|addLayer)\(/', $spiderfyHandlers[0])
    || !preg_match('/state\._spiderfyHiddenLayers = \[\];\s*state\.markers\.clearLayers\(\);/', $source)) {
    throw new RuntimeException('Spiderfy must hide and restore markers without changing cluster membership.');
}
foreach (['map-search', 'map-owner-search', 'map-comune-filter', 'map-assigned-filter', 'find-area-comune', 'find-area-foglio', 'find-area-particella', 'find-area-submit', 'refresh-map', 'cadastral-layer-toggle', 'btn-apply-filter', 'map-category-filter-panel', 'map-visible-count', 'map-visible-owners', 'map-visible-owners-help'] as $id) {
    if (substr_count($page, 'id="' . $id . '"') !== 1) {
        throw new RuntimeException('Missing or duplicated map hook: ' . $id);
    }
    foreach (["analyticspro_render_header('Mappa del territorio'", "'topbar_content' => \$mapHeaderActions", "'topbar_after' => \$mapTopbarControls", 'Cerca indirizzo...', 'Cerca proprietario...'] as $contract) {
        if (!str_contains($page, $contract)) {
            throw new RuntimeException('Missing two-row map header: ' . $contract);
        }
    }
    if (hash('sha256', substr($page, strpos($page, '<div id="analyticspro-app"'))) !== 'bc46562aca46dcc4c0376399493d90a7e8e8b787223830cc01ea7517a14e0a96') {
        throw new RuntimeException('Map overlays and controls below the header must remain unchanged.');
    }
}
foreach (['Non contattato', 'Contattato', 'Da ricontattare', 'Non raggiungibile', 'Layer catastale', 'title="Proprietari"', 'analyticspro-map-actions', 'Disponibile dopo aver aperto i dettagli', 'aria-describedby="map-visible-owners-help"', 'report.php', "analyticspro_asset_url('assets/css/map.css')"] as $text) {
    if (!str_contains($page, $text)) {
        throw new RuntimeException('Missing map presentation: ' . $text);
    }
}
if (!str_contains($css, '.analyticspro-map-drawer .modal-dialog') || !str_contains($css, 'prefers-reduced-motion')) {
    throw new RuntimeException('Drawer styling and reduced motion support are required.');
}
if (!preg_match('/\.analyticspro-map-visible-area\s*\{([^}]*)\}/', $css, $visibleAreaStyles)
    || !preg_match('/padding:\s*20px\s*;/', $visibleAreaStyles[1])
    || !preg_match('/\.analyticspro-map-visible-area\s*\{[^}]*padding:\s*16px\s*;/s', $css)
    || !preg_match('/\.analyticspro-map-owners-help\s*\{[^}]*margin:\s*0 0 12px\s*;/s', $css)) {
    throw new RuntimeException('Visible area content needs consistent padding without negative child margins.');
}

$functions = '';
foreach (['refreshMapToolbarFilters', 'buildOwnerSearchText', 'matchesMapToolbarFilters', 'propertyHeaderFacts', 'buildPropertyCardHtml', 'normalizedPropertyGroupIds', 'updateMapVisibleArea', 'bindMapEditorDrawer', 'prepareMapEditorDrawer', 'updateMapEditorPresentation'] as $name) {
    if (!preg_match('/    function ' . $name . '\(.*?(?=\n    (?:async )?function )/s', $source, $match)) {
        throw new RuntimeException('Missing function: ' . $name);
    }
    $functions .= $match[0] . "\n";
}
$test = <<<'JS'
const assert = require('node:assert/strict');
const nodes = new Map();
class Element {
    constructor(tag = 'div', id = '', className = '') {
        this.tag = tag; this.id = id; this.className = className;
        this.children = []; this.attributes = {}; this.value = ''; this.textContent = '';
        this.classList = { contains: name => this.className.split(' ').includes(name), add: name => { this.className += ' ' + name; } };
    }
    set id(id) { this._id = id; if (id) nodes.set(id, this); }
    get id() { return this._id; }
    appendChild(child) {
        if (child.parentNode) child.parentNode.children = child.parentNode.children.filter(node => node !== child);
        this.children.push(child); child.parentNode = this; return child;
    }
    insertBefore(child, before) {
        this.children.splice(this.children.indexOf(before), 0, child); child.parentNode = this;
    }
    setAttribute(key, value) { this.attributes[key] = value; }
    querySelector(selector) {
        const all = this.children.flatMap(child => [child, ...child.descendants()]);
        return all.find(node => selector[0] === '#' ? node.id === selector.slice(1) : (selector[0] === '.' ? node.classList.contains(selector.slice(1)) : node.tag === selector)) || null;
    }
    descendants() { return this.children.flatMap(child => [child, ...child.descendants()]); }
}
const document = { getElementById: id => nodes.get(id) || null, createElement: tag => new Element(tag) };
const escapeHtml = value => String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
const unitLabel = property => 'F.' + property.foglio + ' P.' + property.particella + '/' + property.subalterno;
const propertyNotesHtml = property => property.notes ? property.notes.map(note => escapeHtml(note.testo)).join(' ') : 'Nessuna nota';
const ownerDetailRows = () => '';
const assignmentNames = () => [];
const STATE_OPTIONS = {};
let openedProperty, detailFetches = 0;
const openEditorModal = id => { openedProperty = id; };
const fetchMapPropertyDetails = async ids => {
    detailFetches++;
    state.properties.forEach(property => { if (ids.includes(property.id)) property.owners = []; });
};
const showMapFeedback = message => { throw new Error(message); };
const scheduleMapVisibleArea = () => {};
let selectedTab;
const bootstrap = { Tab: { getOrCreateInstance: node => ({ show() { selectedTab = node.id; } }) } };
const state = { properties: [], role: 'tenant', subusers: [] };
JS;
$test .= "\n" . $functions . "\n";
$test .= <<<'JS'
const handlers = {};
const element = (visibility = '', pointerEvents = '') => ({ style: { visibility, pointerEvents } });
function opacityMarker(opacity = 1) {
    return {
        options: { opacity }, _icon: element(), _shadow: element(),
        setOpacity(value) { this.options.opacity = value; }
    };
}
const spiderMarker = opacityMarker(0.8);
const otherMarker = opacityMarker(0.4);
otherMarker._icon.style.visibility = 'collapse';
otherMarker._icon.style.pointerEvents = 'auto';
const circle = { options: { opacity: 0.3, fillOpacity: 0.6 }, _path: element('', 'visiblePainted') };
const defaultMarker = opacityMarker(); delete defaultMarker.options.opacity;
const detachedMarker = opacityMarker(0.2);
const clusterChild = opacityMarker(0.7); delete clusterChild._icon; delete clusterChild._shadow;
const otherCluster = opacityMarker(0.9);
otherCluster.getAllChildMarkers = () => [clusterChild, otherMarker];
const markerLayers = new Set([spiderMarker, otherMarker, circle, defaultMarker, detachedMarker, clusterChild]);
let popupClosed = 0;
state._spiderfyHiddenLayers = [];
state.map = { closePopup() { popupClosed++; }, on(name, handler) { handlers['map:' + name] = handler; } };
state.markers = {
    on(name, handler) { handlers[name] = handler; },
    eachLayer(fn) { markerLayers.forEach(fn); },
    getVisibleParent(layer) { return layer === clusterChild ? otherCluster : layer; },
    hasLayer(layer) { return markerLayers.has(layer); },
    removeLayer() { throw new Error('Removing a layer collapses spiderfy'); },
    addLayer() { throw new Error('Adding a layer collapses spiderfy'); }
};
JS;
$test .= "\n" . $spiderfyHandlers[0] . "\n";
$test .= <<<'JS'
handlers.spiderfied({ markers: [spiderMarker] });
assert.equal(popupClosed, 1);
assert.equal(markerLayers.size, 6, 'All markers remain in the cluster tree');
assert.equal(spiderMarker.options.opacity, 0.8);
assert.equal(spiderMarker._icon.style.visibility, '', 'Spiderfied markers stay visible and interactive');
assert.equal(otherMarker.options.opacity, 0);
assert.equal(otherMarker._icon.style.visibility, 'hidden');
assert.equal(otherMarker._icon.style.pointerEvents, 'none');
assert.equal(otherMarker._shadow.style.visibility, 'hidden');
assert.equal(circle._path.style.visibility, 'hidden', 'SVG paths use the DOM fallback');
assert.equal(circle._path.style.pointerEvents, 'none');
assert.equal(otherCluster._icon.style.visibility, 'hidden', 'Other visible clusters are hidden too');
const hiddenCount = state._spiderfyHiddenLayers.length;
handlers.spiderfied({ markers: [spiderMarker] });
assert.equal(state._spiderfyHiddenLayers.length, hiddenCount, 'Repeated events preserve original styles');
const oldPath = circle._path;
circle._path = element('', 'visiblePainted');
const oldClusterIcon = otherCluster._icon;
otherCluster._icon = element();
handlers['map:moveend']();
assert.equal(circle._path.style.visibility, 'hidden', 'New SVG paths remain hidden after panning');
assert.equal(otherCluster._icon.style.visibility, 'hidden', 'New cluster DOM remains hidden after panning');
markerLayers.delete(detachedMarker);
markerLayers.delete(clusterChild);
detachedMarker.setOpacity = () => { throw new Error('Do not restore a removed/replaced layer'); };
handlers.unspiderfied();
assert.equal(otherMarker.options.opacity, 0.4);
assert.equal(otherMarker._icon.style.visibility, 'collapse');
assert.equal(otherMarker._icon.style.pointerEvents, 'auto');
assert.equal(otherMarker._shadow.style.visibility, '');
assert.equal(circle._path.style.visibility, '');
assert.equal(oldPath.style.visibility, '');
assert.equal(circle._path.style.pointerEvents, 'visiblePainted');
assert.equal(circle.options.opacity, 0.3);
assert.equal(circle.options.fillOpacity, 0.6);
assert.equal(defaultMarker.options.opacity, 1);
assert.equal(otherCluster.options.opacity, 0.9);
assert.equal(otherCluster._icon.style.visibility, '');
assert.equal(oldClusterIcon.style.visibility, '');
assert.deepEqual(state._spiderfyHiddenLayers, []);
handlers.unspiderfied();
handlers.spiderfied({ markers: [spiderMarker] });
state._spiderfyHiddenLayers = [];
markerLayers.clear();
handlers.unspiderfied();
assert.deepEqual(state._spiderfyHiddenLayers, [], 'Rerender discards stale references without restoration');
JS;
$test .= <<<'JS'
assert.equal(matchesMapToolbarFilters({}), true, 'No map filters on other pages');
const card = buildPropertyCardHtml({
    id: 10, indirizzo: 'Via Roma', foglio: '6', particella: '726', categoria: 'A/2', piano: '3', subalterno: '41',
    classe: '2', rendita: '100', consistenza: '5', can_edit: false,
    can_delete: false, owners: [], notes: []
}, { mapMode: true });
assert.match(card, /Via Roma.*F\.6 P\.726\/41 · <strong>Categoria:<\/strong> A\/2 · <strong>Piano:<\/strong> 3/);
assert.match(card, /Categoria:<\/strong> A\/2 · <strong>Piano:<\/strong> 3/);
const factsStart = card.indexOf('<div class="small mt-1">');
const headerFacts = card.slice(factsStart, card.indexOf('</div>', factsStart));
assert.match(headerFacts, /<strong>Subalterno:<\/strong> 41/);
assert.doesNotMatch(headerFacts, /<strong>(Categoria|Piano):<\/strong>/);
const emptyHeaderCard = buildPropertyCardHtml({
    id: 11, indirizzo: 'Via Verdi', foglio: '2', particella: '9', subalterno: '', categoria: '', piano: '',
    classe: '', rendita: '', consistenza: '', can_edit: false, can_delete: false, owners: [], notes: []
}, { mapMode: false });
assert.match(emptyHeaderCard, /Categoria:<\/strong> — · <strong>Piano:<\/strong> —/);
const emptyFactsStart = emptyHeaderCard.indexOf('<div class="small mt-1">');
const emptyFacts = emptyHeaderCard.slice(emptyFactsStart, emptyHeaderCard.indexOf('</div>', emptyFactsStart));
assert.match(emptyFacts, /<strong>Subalterno:<\/strong> —/);
assert.doesNotMatch(emptyFacts, /<strong>(Categoria|Piano):<\/strong>/);
const search = new Element('input', 'map-search');
const ownerSearch = new Element('input', 'map-owner-search');
const comune = new Element('select', 'map-comune-filter');
const assigned = new Element('select', 'map-assigned-filter');
state.properties = [
    { comune: 'Milano', assignments: [{ subuser_id: 7, subuser_name: '<Alice>' }] },
    { comune: 'Roma', assignments: [{ subuser_id: 8, subuser_name: 'Bruno' }] }
];
comune.value = 'Milano'; assigned.value = '7';
refreshMapToolbarFilters();
assert.equal(comune.value, 'Milano'); assert.equal(assigned.value, '7');
assert.ok(assigned.innerHTML.includes('&lt;Alice>')); assert.ok(!assigned.innerHTML.includes('<Alice>'));
const property = { comune: 'Milano', indirizzo: 'Via Roma', foglio: '34', owners: [{ nome: 'Mario', cognome: 'Rossi' }], assignments: [{ subuser_id: 7 }] };
assert.equal(matchesMapToolbarFilters(property), true);
search.value = 'VIA ROMA'; assert.equal(matchesMapToolbarFilters(property), true);
search.value = '34'; assert.equal(matchesMapToolbarFilters(property), true);
search.value = 'inesistente'; assert.equal(matchesMapToolbarFilters(property), false);
search.value = '';
ownerSearch.value = 'ROSSI'; assert.equal(matchesMapToolbarFilters(property), true, 'Owner search matches owner surname independently');
ownerSearch.value = 'inesistente'; assert.equal(matchesMapToolbarFilters(property), false);
ownerSearch.value = 'Mario'; search.value = 'Via Roma'; assert.equal(matchesMapToolbarFilters(property), true, 'Address and owner filters combine in AND');
search.value = 'inesistente'; assert.equal(matchesMapToolbarFilters(property), false, 'Address and owner filters both constrain results');
search.value = '';
ownerSearch.value = '';
search.value = ''; comune.value = 'Roma'; assert.equal(matchesMapToolbarFilters(property), false);
comune.value = ''; assigned.value = '8'; assert.equal(matchesMapToolbarFilters(property), false);
assigned.value = ''; assert.equal(matchesMapToolbarFilters(property), true);
const count = new Element('strong', 'map-visible-count');
const ownerCount = new Element('strong', 'map-visible-owners');
const ownerHelp = new Element('small', 'map-visible-owners-help');
state.properties = [
    { id: 1 },
    { id: 2 },
    { id: 3 },
    { id: 4 }
];
let layers = [
    { _analyticsPropertyData: { id: 1, _groupIds: [1, 2] }, getLatLng: () => ({ visible: true }) },
    { _analyticsPropertyData: { id: 3 }, getLatLng: () => ({ visible: true }) },
    { _analyticsPropertyData: { id: 4 }, getLatLng: () => ({ visible: false }) }
];
state.map = { getBounds: () => ({ contains: point => point.visible }) };
state.markers = { eachLayer: fn => layers.forEach(fn) };
updateMapVisibleArea();
assert.equal(count.textContent, '3', 'Count actual grouped property IDs within viewport, not markers');
assert.equal(ownerCount.textContent, '—', 'Do not fabricate zero when existing lazy-loaded owners are unavailable');
assert.equal(ownerHelp.hidden, false, 'Explain that exact owner counts require opening existing details');
assert.equal(detailFetches, 0, 'Viewport summary must never fetch owners upfront');
state.properties[0].owners = [{}, {}];
state.properties[1].owners = [{}];
updateMapVisibleArea(); assert.equal(ownerCount.textContent, '—', 'Partial hydration does not imply a known total');
state.properties[2].owners = [{}, {}];
updateMapVisibleArea(); assert.equal(ownerCount.textContent, '5', 'Reuse cached details when present');
assert.equal(ownerHelp.hidden, true);
layers = []; updateMapVisibleArea();
assert.equal(count.textContent, '0'); assert.equal(ownerCount.textContent, '0');
const modal = new Element('div', 'property-editor-modal', 'modal fade');
const header = modal.appendChild(new Element('div', '', 'modal-header'));
const headerRow = header.appendChild(new Element());
const title = headerRow.appendChild(new Element('h5', '', 'modal-title'));
const ownerSelect = headerRow.appendChild(new Element('div', 'editor-owner-select-wrap'));
const body = modal.appendChild(new Element('div', '', 'modal-body'));
const meta = body.appendChild(new Element('div', 'property-editor-meta'));
const error = body.appendChild(new Element('div', 'property-editor-error'));
const owners = body.appendChild(new Element('div', 'editor-owners-block'));
const row = body.appendChild(new Element('div', '', 'row g-2'));
const noteGroup = row.appendChild(new Element());
noteGroup.appendChild(new Element('label', '', 'label'));
noteGroup.appendChild(new Element('div', 'editor-note-log'));
const note = noteGroup.appendChild(new Element('input', 'editor-note')); note.value = 'unsaved';
const save = modal.appendChild(new Element('button', 'editor-save-btn'));
prepareMapEditorDrawer();
assert.ok(modal.classList.contains('modal') && modal.classList.contains('analyticspro-map-drawer'));
assert.equal(modal.attributes['aria-labelledby'], 'map-editor-address');
assert.equal(ownerSelect.parentNode.id, 'map-editor-pane-owners');
assert.equal(owners.parentNode.id, 'map-editor-pane-owners');
assert.equal(row.parentNode, body, 'Contact management and notes remain visible beneath all tabs');
assert.equal(meta.parentNode, header); assert.equal(error.parentNode, body);
assert.equal(document.getElementById('editor-note'), note); assert.equal(note.value, 'unsaved');
assert.equal(document.getElementById('editor-save-btn'), save, 'Preserve original saving hook');
const descendants = modal.descendants().length;
prepareMapEditorDrawer(); assert.equal(modal.descendants().length, descendants, 'Idempotent drawer layout');
updateMapEditorPresentation({ indirizzo: '<Via>', civico: '5', comune: 'Milano', foglio: '34', rendita: null });
assert.equal(title.textContent, '<Via> 5');
assert.equal(selectedTab, 'map-editor-tab-owners');
assert.ok(nodes.get('map-editor-pane-cadastral').innerHTML.includes('&lt;Via>') === false);
assert.ok(nodes.get('map-editor-pane-cadastral').innerHTML.includes('<dd>34</dd>'));
assert.ok(nodes.get('map-editor-pane-cadastral').innerHTML.includes('<dd>—</dd>'));
assert.ok(nodes.get('map-editor-cadastral-summary').innerHTML.includes('<span>Foglio</span><strong>34</strong>'));
assert.match(nodes.get('map-editor-cadastral-summary').innerHTML, /<span>Subalterno<\/span><strong>—<\/strong>/);
for (const subalterno of ['2', '', null, undefined, 0, '<2>']) {
    updateMapEditorPresentation({ foglio: '2', particella: '632', categoria: 'A07', subalterno });
    const summary = nodes.get('map-editor-cadastral-summary').innerHTML;
    const expected = subalterno === '' || subalterno == null ? '—' : escapeHtml(subalterno);
    assert.ok(summary.includes('<span>Subalterno</span><strong>' + expected + '</strong>'));
    assert.doesNotMatch(summary, /Categoria/);
    assert.match(nodes.get('map-editor-pane-cadastral').innerHTML, /<dt>Categoria<\/dt><dd>A07<\/dd>/);
    assert.ok(nodes.get('map-editor-pane-cadastral').innerHTML.includes('<dt>Subalterno</dt><dd>' + expected + '</dd>'));
}
assert.equal(nodes.get('map-editor-pane-activity').innerHTML, 'Nessuna nota', 'Only render existing notes, no fabricated timeline');
(async () => {
    function marker(property) {
        return {
            _analyticsPropertyData: property,
            on(event, handler) { this[event] = handler; },
            closePopup() { this.closed = true; }
        };
    }
    state.properties = [{ id: 1, owners: [] }];
    let layer = marker({ id: 1, can_edit: true });
    bindMapEditorDrawer(layer); await layer.click();
    assert.equal(openedProperty, 1); assert.ok(layer.closed); assert.equal(detailFetches, 0);
    state.properties = [{ id: 2 }];
    layer = marker({ id: 2, can_edit: true });
    bindMapEditorDrawer(layer); await layer.click();
    assert.equal(openedProperty, 2); assert.equal(detailFetches, 1, 'Fetch only selected marker details on demand');
    state.properties = [{ id: 3 }];
    layer = marker({ id: 3, can_edit: true });
    layer._analyticsDetailsPromise = Promise.resolve().then(() => { state.properties[0].owners = []; });
    bindMapEditorDrawer(layer); await layer.click();
    assert.equal(openedProperty, 3); assert.equal(detailFetches, 1, 'Reuse in-flight popup detail request');
    const shell = new Element('div', 'analyticspro-map-shell');
    const readOnlyPopup = new Element('div', '', 'leaflet-popup');
    layer = marker({ id: 4, can_edit: false });
    layer.getPopup = () => ({ getElement: () => readOnlyPopup });
    bindMapEditorDrawer(layer); await layer.click();
    assert.equal(openedProperty, 3); assert.ok(!layer.closed, 'Keep existing read-only behavior and edit guards');
    layer.popupopen();
    assert.equal(readOnlyPopup.parentNode, shell, 'Read-only details use a right-hand drawer, not an anchored center popup');
    assert.ok(readOnlyPopup.classList.contains('analyticspro-map-readonly-drawer'));
    assert.equal(readOnlyPopup.attributes['aria-label'], 'Dettaglio immobile · sola lettura');
    assert.equal(detailFetches, 1, 'Read-only presentation does not fetch or open an edit drawer');
    console.log('PASS: map UI counts, spaced spiderfy, direct marker drawer and visible contact/note hooks');
})().catch(error => { console.error(error); process.exitCode = 1; });
JS;
$process = proc_open(['node'], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('Unable to start Node test runner.');
}
fwrite($pipes[0], $test);
fclose($pipes[0]);
exit(proc_close($process));
