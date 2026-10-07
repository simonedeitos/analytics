<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../assets/js/analyticspro.js');
$functions = '';
foreach (['loadProperties', 'renderMap', 'normalizedPropertyGroupIds', 'fetchMapPropertyDetails', 'bindMapPopup', 'refreshSavedMapProperties', 'savePropertyPayload'] as $name) {
    preg_match('/    (?:async )?function ' . $name . '\(.*?(?=\n    (?:async )?function )/s', $source, $match);
    if (!$match) {
        throw new RuntimeException('Funzione non trovata: ' . $name);
    }
    $functions .= ($name === 'renderMap' ? str_replace('function renderMap(', 'function renderMapForTest(', $match[0]) : $match[0]) . "\n";
}

$test = <<<'JS'
const assert = require('node:assert/strict');
const state = { propertiesEndpoint: '/properties', propertyUpdateEndpoint: '/update', properties: [], subusers: [], csrfToken: 'test' };
const document = { getElementById: id => id === 'map-fullpage' ? {} : null };
const window = { setTimeout, addEventListener() {} };
const L = { latLngBounds: points => ({ pad: () => points }) };
const scheduleMapInvalidateSize = () => {};
const focusMapOnTargetMarker = () => true;
let calls = [], mapRenders = 0, filteredStates = ['', 'interessato'], filteredCategories = null, failDetails = false;
const withTenant = url => url;
const captureMapView = () => ({ center: [45, 9], zoom: 12 });
const refreshMapCategoryFilters = () => {};
const renderMap = () => { mapRenders++; state.map = {}; };
const escapeHtml = text => String(text).replace(/</g, '&lt;');
const buildPopupHtml = property => JSON.stringify(property.owners);
const getStatiFilter = () => filteredStates;
const getCategorieFilter = () => filteredCategories;
const groupHasApproximateCoordinates = property => property.coord_source === 'approx';
const buildApproximateMarkerIcon = color => color;
let groupPropertiesByUnit = records => [Object.assign({}, records[0], {
    _groupIds: records.map(p => p.id), _groupProperties: records, owners: records.flatMap(p => p.owners || [])
})];
const createMapMarker = property => marker(property);
const db = new Map([
    [1, { id: 1, categoria: 'A2', stato: 'interessato', colore_marker: '#198754', owners: [{ nome: 'Uno' }], notes: [{ testo: 'Nota' }] }],
    [2, { id: 2, categoria: 'A2', stato: 'interessato', colore_marker: '#198754', owners: [{ nome: 'Due' }] }],
    [3, { id: 3, categoria: 'A7', stato: '', owners: [] }]
]);
async function api(url, options) {
    calls.push({ url, options });
    if (url === '/update') return { updated_ids: [1, 2] };
    if (url.includes('view=map')) return { properties: [{ id: 1 }, { id: 2 }, { id: 3 }], subusers: [{ id: 10 }] };
    if (failDetails) throw new Error('<errore>');
    const ids = new URL(url, 'http://localhost').searchParams.get('property_ids').split(',').map(Number);
    return { properties: ids.map(id => db.get(id)) };
}
function marker(property) {
    return {
        _analyticsPropertyData: property, options: {}, open: true,
        bindPopup(fn) { this.popup = fn; }, on(event, fn) { this[event] = fn; },
        isPopupOpen() { return this.open; }, setPopupContent(html) { this.content = html; },
        setStyle(style) { this.style = style; }, setIcon(icon) { this.icon = icon; }
    };
}
state.markers = {
    layers: new Set(), removeLayer(layer) { this.layers.delete(layer); },
    addLayer(layer) { this.layers.add(layer); }, hasLayer(layer) { return this.layers.has(layer); },
    refreshClusters() { this.refreshed = true; },
    clearLayers() { this.layers.clear(); },
    addLayers(layers) { this.bulkAdds = (this.bulkAdds || 0) + 1; layers.forEach(layer => this.layers.add(layer)); }
};
JS;
$test .= "\n" . $functions . "\n";
$test .= <<<'JS'
(async () => {
    await loadProperties();
    assert.equal(calls.length, 1, 'Una sola richiesta al caricamento mappa');
    assert.ok(calls[0].url.includes('view=map'));
    assert.deepEqual(state.assignedProperties, []);
    assert.equal(mapRenders, 2, 'Mappa base inizializzata prima del caricamento');

    const group = groupPropertiesByUnit(state.properties.slice(0, 2))[0];
    const layer = marker(group);
    bindMapPopup(layer);
    assert.ok(layer.popup(layer).includes('Caricamento'), 'Non generare popup completi per i riepiloghi');
    calls = [];
    await Promise.all([layer.popupopen(), layer.popupopen()]);
    assert.equal(calls.length, 1, 'Richieste concorrenti dello stesso popup accorpate');
    assert.ok(calls[0].url.endsWith('property_ids=1,2'), 'Caricare tutti i membri del gruppo');
    assert.ok(layer.content.includes('Uno') && layer.content.includes('Due'));
    await layer.popupopen();
    assert.equal(calls.length, 1, 'Dettagli in cache al secondo click');

    const untouched = state.properties[2];
    state.mapLayersByProperty = new Map([[1, layer], [2, layer]]);
    state.markers.addLayer(layer);
    calls = [];
    const beforeSaveRenders = mapRenders;
    await savePropertyPayload({ property_id: 1, property_ids: [1, 2], note: 'Nota' });
    assert.equal(calls.length, 2, 'POST seguito solo da GET dei record interessati');
    assert.ok(calls[1].url.endsWith('property_ids=1,2'));
    assert.equal(mapRenders, beforeSaveRenders, 'Non ricostruire tutta la mappa dopo il salvataggio');
    assert.equal(state.properties[2], untouched, 'Preservare immobili non modificati');
    assert.equal(layer.style.fillColor, '#198754');
    assert.ok(state.markers.refreshed);

    filteredStates = [''];
    await refreshSavedMapProperties([1]);
    assert.ok(!state.markers.hasLayer(layer), 'Rimuovere un marker che non soddisfa più i filtri');
    filteredStates = ['', 'interessato'];
    await refreshSavedMapProperties([1]);
    assert.ok(state.markers.hasLayer(layer));
    filteredCategories = ['A7'];
    await refreshSavedMapProperties([1]);
    assert.ok(!state.markers.hasLayer(layer), 'Preservare filtro categoria durante il salvataggio');
    filteredCategories = null;
    await refreshSavedMapProperties([1], true);
    assert.notEqual(state.mapLayersByProperty.get(1), layer, 'Sostituire solo il marker con coordinate cambiate');
    assert.equal(state.mapLayersByProperty.get(1), state.mapLayersByProperty.get(2));

    const retryLayer = marker(groupPropertiesByUnit([{ id: 3 }])[0]);
    bindMapPopup(retryLayer);
    state.properties[2] = { id: 3 };
    failDetails = true;
    await retryLayer.popupopen();
    assert.ok(retryLayer.content.includes('&lt;errore>'), 'Errori popup escaped');
    assert.equal(retryLayer._analyticsDetailsPromise, null);
    failDetails = false;
    retryLayer.open = false;
    await retryLayer.popupopen();
    assert.ok(!retryLayer.content.includes('[]'), 'Non riaprire popup chiusi durante il caricamento');

    groupPropertiesByUnit = records => records.map(property => Object.assign({}, property, { _groupIds: [property.id] }));
    state.map = { fitBounds() {}, invalidateSize() {} };
    state.properties = Array.from({ length: 450 }, (_, id) => ({ id: id + 1, lat: 45, lng: 9, stato: '' }));
    const bulkAddsBefore = state.markers.bulkAdds || 0;
    let yielded = false;
    const render = renderMapForTest();
    setTimeout(() => { yielded = true; }, 0);
    await render;
    assert.ok(yielded, 'Creazione marker a blocchi, senza monopolizzare il thread');
    assert.equal(state.markers.layers.size, 450);
    assert.equal(state.markers.bulkAdds, bulkAddsBefore + 1, 'Inserimento bulk nel cluster');
    const staleRender = renderMapForTest();
    state.properties = [{ id: 999, lat: 45, lng: 9, stato: '' }];
    await renderMapForTest();
    await staleRender;
    assert.equal(state.markers.layers.size, 1, 'Non applicare rendering obsoleti dopo un refresh');
    assert.ok(state.mapLayersByProperty.has(999));
    console.log('PASS: map lazy details, cache, retries, grouped saves and targeted marker refresh');
})().catch(error => { console.error(error); process.exitCode = 1; });
JS;

$tmp = tempnam(sys_get_temp_dir(), 'analyticspro-map-test-');
try {
    file_put_contents($tmp, $test);
    passthru('node ' . escapeshellarg($tmp), $exitCode);
} finally {
    unlink($tmp);
}
exit($exitCode);
