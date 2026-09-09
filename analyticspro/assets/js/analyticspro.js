(function () {
    'use strict';

    const root = document.getElementById('analyticspro-app');
    if (!root) return;
    const importOverlayEl = document.getElementById('import-overlay');

    const STATE_OPTIONS = {
        '': 'Non impostato',
        non_interessato: 'Non Interessato',
        interessato: 'Interessato',
        contattato: 'Contattato',
        da_contattare: 'Da Contattare',
        non_raggiungibile: 'Non Raggiungibile',
        in_vendita_noi: 'In Vendita NOI',
        in_vendita_altri: 'In Vendita ALTRI',
        altro: 'Altro',
    };

    const MARKER_COLOR_PALETTE = [
        { label: 'Rosso · P1', value: '#dc3545' },
        { label: 'Arancio · P2', value: '#fd7e14' },
        { label: 'Giallo · P3', value: '#ffc107' },
        { label: 'Verde · P4', value: '#198754' },
        { label: 'Azzurro · P5', value: '#0dcaf0' },
        { label: 'Blu · P6', value: '#0d6efd' },
        { label: 'Fucsia · P7', value: '#d63384' },
        { label: 'Viola · P8', value: '#6f42c1' }
    ];
    const CATASTRAL_WMS_BASE_URL = 'https://wms.cartografia.agenziaentrate.gov.it/inspire/wms/ows01.php?language=ita';
    const CATASTRAL_ADE_AJAX_URL = 'https://wms.cartografia.agenziaentrate.gov.it/inspire/ajax/ajax.php?op=getDatiOggetto';
    const CATASTRAL_OPACITY_STORAGE_KEY = 'cadastral-layer-opacity';
    const DEFAULT_CATASTRAL_OPACITY = 0.5;
    const CATASTRAL_MIN_ZOOM = 10;
    const CATASTRAL_LOOKUP_TIMEOUT_MS = 30000;
    const CATASTRAL_RESOLVE_LOCATION_TIMEOUT_MS = 40000;
    const CATASTRAL_TILE_RETRY_LIMIT = 2;
    const CATASTRAL_TILE_RETRY_DELAY_MS = 900;
    const CATASTRAL_ERROR_TILE_URL = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==';
    const MANUAL_RECORD_AUTOFILL_TITLE = 'Compilato automaticamente dalla mappa';
    const IMPORT_PHASE_WEIGHTS = {
        read: { start: 0, end: 10, label: 'Lettura file' },
        analyze: { start: 10, end: 20, label: 'Analisi duplicati' },
        save: { start: 20, end: 45, label: 'Salvataggio dati' },
        enrich: { start: 45, end: 100, label: 'Geolocalizzazione' }
    };

    var state = {
        csrfToken: document.querySelector('meta[name="csrf-token"]') ? document.querySelector('meta[name="csrf-token"]').content : '',
        role: root.dataset.role,
        tenantId: root.dataset.tenantId || '',
        selectedTenant: root.dataset.selectedTenant || '',
        canImport: root.dataset.canImport === '1',
        canExport: root.dataset.canExport === '1',
        canViewReports: root.dataset.canViewReports === '1',
        canViewAnalytics: root.dataset.canViewAnalytics === '1',
        canViewPhone: root.dataset.canViewPhone === '1',
        propertiesEndpoint: root.dataset.propertiesEndpoint || '',
        propertyUpdateEndpoint: root.dataset.propertyUpdateEndpoint || '',
        importEndpoint: root.dataset.importEndpoint || '',
        importProgressEndpoint: root.dataset.importProgressEndpoint || '',
        enrichChunkEndpoint: root.dataset.enrichChunkEndpoint || '',
        missingCoordinatesStatsEndpoint: root.dataset.missingCoordinatesStatsEndpoint || '',
        adeJobsEndpoint: root.dataset.adeJobsEndpoint || '',
        adeManualFilesEndpoint: root.dataset.adeManualFilesEndpoint || '',
        dashboardStatsEndpoint: root.dataset.dashboardStatsEndpoint || '',
        dashboardPage: root.dataset.dashboardPage || '',
        dashboardPeriod: root.dataset.dashboardPeriod || 'all',
        dashboardComune: root.dataset.dashboardComune || '',
        dashboardCategory: root.dataset.dashboardCategory || '',
        dashboardMapUrl: root.dataset.dashboardMapUrl || '',
        reportQuery: root.dataset.reportQuery || '',
        propertyDeleteEndpoint: root.dataset.propertyDeleteEndpoint || '',
        findAreaEndpoint: root.dataset.findAreaEndpoint || '',
        findAreaComuniEndpoint: root.dataset.findAreaComuniEndpoint || '',
        featureInfoEndpoint: root.dataset.featureInfoEndpoint || '',
        wmsProxyEndpoint: root.dataset.wmsProxyEndpoint || '',
        properties: [],
        subusers: [],
        map: null,
        markers: null,
        clusterIconCache: null,
        charts: {},
        tables: {},
        overlay: importOverlayEl ? new bootstrap.Modal(importOverlayEl) : null,
        mapStatiFilter: null,
        mapCategoriaFilter: null,
        currentImportStats: null,
        phoneConfirmPending: false,
        pendingMapView: null,
        findAreaComuneMap: {},
        findAreaMarker: null,
        findAreaBoundsLayer: null,
        findAreaAutocompleteItems: [],
        cadastralLayer: null,
        cadastralLayerEnabled: false,
        cadastralClickBound: false,
        cadastralPopup: null,
        manualRecordModal: null,
        manualRecordContext: null,
        pendingNewMarkerFocus: null,
        cadastralTileWarningShown: false,
        mapInvalidateTimer: 0,
        dashboardMiniMap: null,
        dashboardMiniMarkers: null,
        importUiFinalized: false,
        importLogSnapshots: {
            attempt_failures: {},
            failure_codes: {}
        }
    };

    state.mapStatiFilter = Object.keys(STATE_OPTIONS).slice();
    state.mapCategoriaFilter = null;
    state.cadastralLayerEnabled = readLocalFlag('cadastral-layer-enabled');

    function resolveManualRecordLockedFields(values, candidateFields) {
        return (candidateFields || []).filter(function (fieldName) {
            return String(values && values[fieldName] !== undefined && values[fieldName] !== null ? values[fieldName] : '').trim() !== '';
        });
    }

    function getStatiFilter() {
        if (state.mapStatiFilter && Array.isArray(state.mapStatiFilter) && state.mapStatiFilter.length > 0) {
            return state.mapStatiFilter;
        }
        return Object.keys(STATE_OPTIONS).slice();
    }

    function getCategorieFilter() {
        return Array.isArray(state.mapCategoriaFilter) ? state.mapCategoriaFilter : null;
    }

    function normalizeComuneValue(value) {
        if (value === null || value === undefined) {
            return value;
        }
        var normalized = String(value);
        if (normalized === '') {
            return normalized;
        }
        return normalized.toLocaleUpperCase('it-IT');
    }

    function cadastralButtonValues(button) {
        return {
            'Provincia': button.dataset.provincia || '',
            'Comune': normalizeComuneValue(button.dataset.comune || ''),
            'Codice Catastale': button.dataset.codCatastale || '',
            'Sezione': button.dataset.sezione || '',
            'Foglio': button.dataset.foglio || '',
            'Particella': button.dataset.particella || '',
            'Subalterno': button.dataset.subalterno || '',
            'Categoria': button.dataset.categoria || '',
            'Indirizzo': button.dataset.indirizzo || '',
            'Civico': button.dataset.civico || '',
            'Quota': '',
            'Latitudine': button.dataset.lat || '',
            'Longitudine': button.dataset.lng || '',
        };
    }

    function parseFiniteCoordinate(value) {
        var normalized = String(value === null || value === undefined ? '' : value).trim().replace(',', '.');
        if (normalized === '') return null;
        var parsed = Number(normalized);
        return Number.isFinite(parsed) ? parsed : null;
    }

    function formatCoordinateSummary(lat, lng) {
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return '';
        return Number(lat).toFixed(6) + ', ' + Number(lng).toFixed(6);
    }

    function mergeCadastralManualRecordValues(baseValues, details) {
        var merged = Object.assign({}, baseValues);
        var baseLat = parseFiniteCoordinate(baseValues && baseValues.Latitudine);
        var baseLng = parseFiniteCoordinate(baseValues && baseValues.Longitudine);
        if (!details || typeof details !== 'object') {
            if (baseLat !== null) merged.Latitudine = String(baseLat);
            if (baseLng !== null) merged.Longitudine = String(baseLng);
            return merged;
        }
        [['Provincia', details.provincia], ['Comune', normalizeComuneValue(details.comune)], ['Codice Catastale', details.cod_catastale], ['Sezione', details.sezione], ['Foglio', details.foglio], ['Particella', details.particella], ['Subalterno', details.subalterno], ['Categoria', details.categoria], ['Indirizzo', details.indirizzo], ['Civico', details.civico]].forEach(function (entry) {
            if (String(entry[1] || '').trim() !== '') {
                merged[entry[0]] = String(entry[1]);
            }
        });
        var resolvedLat = parseFiniteCoordinate(details.lat);
        var resolvedLng = parseFiniteCoordinate(details.lng);
        merged.Latitudine = String(resolvedLat !== null ? resolvedLat : (baseLat !== null ? baseLat : ''));
        merged.Longitudine = String(resolvedLng !== null ? resolvedLng : (baseLng !== null ? baseLng : ''));
        return merged;
    }

    function openCadastralManualRecord(values, lockResolvedLocation, noticeMessage) {
        var candidateFields = ['Codice Catastale', 'Sezione', 'Foglio', 'Particella', 'Subalterno', 'Categoria', 'Indirizzo', 'Civico'];
        if (lockResolvedLocation) {
            candidateFields = ['Provincia', 'Comune'].concat(candidateFields);
        }
        openManualRecordModal(values, resolveManualRecordLockedFields(values, candidateFields), { fromMap: true });
        setManualRecordFeedback(noticeMessage || '', noticeMessage ? 'warning' : 'warning');
    }

    function setCadastralAddButtonLoading(button, loading) {
        if (!button) return;
        if (loading) {
            if (!button.dataset.originalHtml) {
                button.dataset.originalHtml = button.innerHTML;
            }
            button.disabled = true;
            button.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Ricerca comune e provincia…';
            return;
        }
        button.disabled = false;
        if (button.dataset.originalHtml) {
            button.innerHTML = button.dataset.originalHtml;
        }
    }

    function propertyCanViewPhone(property) {
        if (property && Object.prototype.hasOwnProperty.call(property, 'can_view_phone')) {
            return !!property.can_view_phone;
        }
        return state.canViewPhone;
    }

    function paletteEntryByColor(color) {
        return MARKER_COLOR_PALETTE.find(function (item) { return item.value.toLowerCase() === String(color || '').toLowerCase(); }) || null;
    }

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value).replace(/[&<>'"]/g, function (char) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[char];
        });
    }

    function localStorageKey(key) {
        return 'analyticspro:' + key;
    }

    function readLocalFlag(key) {
        try {
            return window.localStorage.getItem(localStorageKey(key)) === '1';
        } catch (error) {
            return false;
        }
    }

    function writeLocalFlag(key, enabled) {
        try {
            window.localStorage.setItem(localStorageKey(key), enabled ? '1' : '0');
        } catch (error) {
        }
    }

    function setQueryParam(key, value) {
        var url = new URL(window.location.href);
        if (value === null || value === undefined || value === '') {
            url.searchParams.delete(key);
        } else {
            url.searchParams.set(key, value);
        }
        window.history.replaceState({}, '', url.toString());
    }

    function getStoredCadastralOpacity() {
        try {
            var savedValue = window.localStorage.getItem(localStorageKey(CATASTRAL_OPACITY_STORAGE_KEY));
            if (savedValue === null) return DEFAULT_CATASTRAL_OPACITY;
            var saved = parseFloat(savedValue);
            if (!Number.isFinite(saved)) return DEFAULT_CATASTRAL_OPACITY;
            if (saved < 0.15) return DEFAULT_CATASTRAL_OPACITY;
            return Math.min(1, Math.max(0, saved));
        } catch (error) {
            return DEFAULT_CATASTRAL_OPACITY;
        }
    }

    function scheduleMapInvalidateSize(delayMs) {
        window.clearTimeout(state.mapInvalidateTimer || 0);
        state.mapInvalidateTimer = window.setTimeout(function () {
            if (state.map) {
                state.map.invalidateSize();
            }
        }, Number.isFinite(delayMs) ? delayMs : 120);
    }

    function setStoredCadastralOpacity(value) {
        try {
            window.localStorage.setItem(localStorageKey(CATASTRAL_OPACITY_STORAGE_KEY), String(Math.min(1, Math.max(0, value))));
        } catch (error) {
        }
    }

    function quotaLabel(value) {
        var quota = String(value || '').trim();
        return quota ? 'Quota ' + quota : '';
    }

    function ownerQuotaLabel(owner, property) {
        return quotaLabel((owner && owner.quota) || (property && property.quota) || '');
    }

    function ownerTitolaritaLabel(owner, property) {
        return String((owner && owner.titolarita) || (property && property.titolarita) || '').trim();
    }

    function parseDob(raw) {
        if (!raw) return null;
        var parts = String(raw).split('-');
        if (parts.length === 3) return new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
        return null;
    }

    function calcAge(raw) {
        var dob = parseDob(raw);
        if (!dob || Number.isNaN(dob.getTime())) return null;
        var now = new Date();
        var age = now.getFullYear() - dob.getFullYear();
        var m = now.getMonth() - dob.getMonth();
        if (m < 0 || (m === 0 && now.getDate() < dob.getDate())) age--;
        return age;
    }

    function ageGroup(age) {
        if (age === null) return 'N/D';
        if (age < 30) return '<30';
        if (age < 45) return '30-44';
        if (age < 60) return '45-59';
        if (age < 75) return '60-74';
        return '75+';
    }

    function defaultColorForState(stateKey) {
        var map = {
            non_interessato: '#dc3545',
            interessato: '#198754',
            contattato: '#0dcaf0',
            da_contattare: '#0d6efd',
            non_raggiungibile: '#6c757d',
            in_vendita_noi: '#fd7e14',
            in_vendita_altri: '#ffc107',
            altro: '#6f42c1',
        };
        return map[stateKey] || '#0d6efd';
    }

    function formatNoteTimestamp(raw) {
        var value = String(raw || '').trim();
        var match = value.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        if (match) {
            return match[3] + '/' + match[2] + '/' + match[1] + ' ' + match[4] + ':' + match[5];
        }
        return '';
    }

    function noteLogLine(note) {
        var text = String((note && note.testo) || '').trim();
        if (/^\[\d{2}\/\d{2}\/\d{4}\s\d{2}:\d{2}\]\s-\s/u.test(text)) {
            return text;
        }
        if (text !== '') {
            var fallbackTs = formatNoteTimestamp(note && note.created_at);
            return fallbackTs ? ('[' + fallbackTs + '] - Nota: ' + text) : text;
        }
        return '';
    }

    function clampPercent(value) {
        return Math.min(100, Math.max(0, Number(value) || 0));
    }

    function colorOptionTextColor(color) {
        var hex = String(color || '').replace('#', '');
        if (!/^[0-9a-f]{6}$/i.test(hex)) return '#212529';
        var red = parseInt(hex.slice(0, 2), 16);
        var green = parseInt(hex.slice(2, 4), 16);
        var blue = parseInt(hex.slice(4, 6), 16);
        var brightness = ((red * 299) + (green * 587) + (blue * 114)) / 1000;
        return brightness >= 150 ? '#212529' : '#ffffff';
    }

    function colorOptionLabel(color, fallbackLabel) {
        var entry = paletteEntryByColor(color);
        return entry ? entry.label : (fallbackLabel || 'Colore personalizzato');
    }

    function colorIdentifier(color) {
        return String(color || '').replace('#', '').toUpperCase();
    }

    function colorOptionHtml(color, label, selected) {
        return '<option value="' + escapeHtml(color) + '" style="background-color:' + escapeHtml(color) + ';color:' + escapeHtml(colorOptionTextColor(color)) + ';"' + (selected ? ' selected' : '') + '>' + escapeHtml(label) + '</option>';
    }

    function updateColorSelectAppearance(select) {
        if (!select) return;
        var color = select.value || '';
        if (!/^#[0-9a-f]{6}$/i.test(color)) {
            select.style.removeProperty('background-color');
            select.style.removeProperty('color');
            return;
        }
        select.style.backgroundColor = color;
        select.style.color = colorOptionTextColor(color);
    }

    function formatDobWithAge(raw) {
        var dob = parseDob(raw);
        if (!dob || Number.isNaN(dob.getTime())) return raw || '';
        var gg = String(dob.getDate()).padStart(2, '0');
        var mm = String(dob.getMonth() + 1).padStart(2, '0');
        var yyyy = dob.getFullYear();
        var age = calcAge(raw);
        return gg + '-' + mm + '-' + yyyy + (age !== null ? ' (' + age + ' anni)' : '');
    }

    function splitPhoneNumbers(raw) {
        if (!raw) return [];
        var chunks = String(raw).split(/[;,]/).map(function (item) { return item.trim(); }).filter(Boolean);
        return chunks.filter(function (item, index) { return chunks.indexOf(item) === index; });
    }

    function buildPhoneChips(raw) {
        var phones = splitPhoneNumbers(raw);
        if (!phones.length) return '';
        return '<div class="d-flex flex-wrap gap-1 mt-1">' + phones.map(function (phone) {
            return '<button type="button" class="btn btn-outline-primary btn-sm copy-phone-btn" data-phone="' + escapeHtml(phone) + '" data-default-label="' + escapeHtml(phone) + '">' + escapeHtml(phone) + '</button>';
        }).join('') + '</div>';
    }

    function buildEditablePhoneChips(raw, propertyId, ownerId, canDelete) {
        var phones = splitPhoneNumbers(raw);
        var chipsHtml = phones.length
            ? '<div class="d-flex flex-wrap gap-1 mt-1">' + phones.map(function (phone) {
            var removeBtn = canDelete
                ? '<button type="button" class="btn btn-outline-danger btn-sm remove-owner-phone-btn"'
                    + ' data-property-id="' + escapeHtml(String(propertyId)) + '"'
                    + ' data-owner-id="' + escapeHtml(String(ownerId)) + '"'
                    + ' data-phone="' + escapeHtml(phone) + '"'
                    + ' title="Elimina numero" aria-label="Elimina numero ' + escapeHtml(phone) + '">\u2715</button>'
                : '';
            return '<span class="d-inline-flex align-items-center gap-1">'
                + '<button type="button" class="btn btn-outline-primary btn-sm copy-phone-btn" data-phone="' + escapeHtml(phone) + '" data-default-label="' + escapeHtml(phone) + '">' + escapeHtml(phone) + '</button>'
                + removeBtn
                + '</span>';
        }).join('') + '</div>'
            : '<span class="text-muted small">Nessun telefono</span>';
        var addControls = canDelete
            ? '<div class="d-inline-flex align-items-center gap-1 mt-1 owner-phone-add-controls" data-property-id="' + escapeHtml(String(propertyId)) + '" data-owner-id="' + escapeHtml(String(ownerId)) + '">'
                + '<button type="button" class="btn btn-outline-success btn-sm add-owner-phone-toggle-btn" title="Aggiungi numero">[+]</button>'
                + '<input type="text" class="form-control form-control-sm d-none add-owner-phone-input" style="max-width:180px;" placeholder="Nuovo numero">'
                + '<button type="button" class="btn btn-success btn-sm d-none add-owner-phone-save-btn">Aggiungi</button>'
                + '<button type="button" class="btn btn-outline-secondary btn-sm d-none add-owner-phone-cancel-btn">Annulla</button>'
                + '</div>'
            : '';
        return chipsHtml + addControls;
    }

    function copyTextToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise(function (resolve, reject) {
            try {
                var input = document.createElement('textarea');
                input.value = text;
                input.setAttribute('readonly', 'readonly');
                input.style.position = 'fixed';
                input.style.opacity = '0';
                document.body.appendChild(input);
                input.select();
                document.execCommand('copy');
                document.body.removeChild(input);
                resolve();
            } catch (error) {
                reject(error);
            }
        });
    }

    function colorPaletteOptions(selected, legacyColor) {
        var options = [];
        if (legacyColor && !paletteEntryByColor(legacyColor)) {
            options.push(colorOptionHtml(legacyColor, colorOptionLabel(legacyColor, 'Colore attuale'), legacyColor === selected));
        }
        return options.join('') + MARKER_COLOR_PALETTE.map(function (item) {
            return colorOptionHtml(item.value, item.label, item.value === selected);
        }).join('');
    }

    function updateEditorColorPreview(color) {
        var preview = document.getElementById('editor-color-preview');
        if (!preview) return;
        preview.style.backgroundColor = color || '#0d6efd';
        preview.setAttribute('aria-label', 'Colore selezionato ' + (color || '#0d6efd'));
    }

    function updateReportFilterColorPreview(color) {
        var preview = document.getElementById('report-filter-color-preview');
        if (!preview) return;
        preview.style.backgroundColor = color || '#dee2e6';
        preview.style.opacity = color ? '1' : '0.45';
        preview.setAttribute('aria-label', color ? 'Filtro colore ' + color : 'Filtro colore non selezionato');
    }

    function propertyHeaderFacts(property) {
        return [
            { label: 'Classe', value: property.classe || '—' },
            { label: 'Rendita', value: property.rendita || '—' },
            { label: 'Piano', value: property.piano || '—' },
            { label: 'Consistenza', value: property.consistenza || '—' }
        ];
    }

    function importProgressLabel(saved, total) {
        return 'Caricato ' + saved + ' su ' + total + ' righe';
    }

    async function api(url, options) {
        options = options || {};
        var allowErrorPayload = options.allowErrorPayload === true;
        var requestOptions = {};
        for (var k in options) {
            if (k !== 'allowErrorPayload') requestOptions[k] = options[k];
        }
        var response = await fetch(url, requestOptions);
        if (response.status === 401) {
            var loginUrl = (state.adeJobsEndpoint || state.propertiesEndpoint || '').replace(/\/api\/.*$/, '/login.php') || 'login.php';
            window.location.href = loginUrl;
            return new Promise(function () {});
        }
        var payload = await response.json();
        if (!response.ok || payload.ok === false) {
            if (allowErrorPayload) return payload;
            var error = new Error(payload.error || 'Operazione non riuscita');
            error.payload = payload;
            error.status = response.status;
            throw error;
        }
        return payload;
    }

    function withTenant(url) {
        if (state.role !== 'admin' || !state.selectedTenant || state.selectedTenant === 'all') {
            return url;
        }
        return url + (url.indexOf('?') !== -1 ? '&' : '?') + 'tenant_id=' + encodeURIComponent(state.selectedTenant);
    }

    function currentDashboardFilters() {
        var comuneControl = document.querySelector('[data-dashboard-filter-control="comune"]');
        var categoryControl = document.querySelector('[data-dashboard-filter-control="category"]');
        return {
            period: 'all',
            comune: comuneControl ? comuneControl.value : (state.dashboardComune || ''),
            category: categoryControl ? categoryControl.value : (state.dashboardCategory || ''),
        };
    }

    function buildDashboardStatsUrl(forceRefresh) {
        var filters = currentDashboardFilters();
        var url = state.dashboardStatsEndpoint || '';
        if (!url) return '';
        var sep = url.indexOf('?') === -1 ? '?' : '&';
        url += sep + 'period=all';
        if (filters.comune) url += '&comune=' + encodeURIComponent(filters.comune);
        if (filters.category) url += '&category=' + encodeURIComponent(filters.category);
        if (forceRefresh) url += '&refresh=1';
        return withTenant(url);
    }

    async function loadDashboardStats(forceRefresh) {
        if (!state.dashboardStatsEndpoint || !state.dashboardPage) return;
        var filters = currentDashboardFilters();
        state.dashboardPeriod = 'all';
        state.dashboardComune = filters.comune;
        state.dashboardCategory = filters.category;
        var payload = await api(buildDashboardStatsUrl(forceRefresh), {
            headers: { 'X-CSRF-Token': state.csrfToken }
        });
        renderDashboardStats(payload || {});
        setQueryParam('period', 'all');
        setQueryParam('comune', state.dashboardComune);
        setQueryParam('category', state.dashboardCategory);
    }

    async function loadProperties(options) {
        options = options || {};
        if (options.preserveMapView) {
            state.pendingMapView = captureMapView();
        } else {
            state.pendingMapView = null;
        }
        var results = await Promise.all([
            api(withTenant(state.propertiesEndpoint + '?mode=all')),
            api(withTenant(state.propertiesEndpoint + '?mode=assigned' + (state.role !== 'subuser' ? '&subuser_id=' : ''))),
        ]);
        state.properties = results[0].properties || [];
        state.subusers = results[0].subusers || [];
        state.assignedProperties = results[1].properties || [];
        updateKpis();
        renderMap();
        renderAssignedTable();
        if (state.canViewReports || state.role !== 'subuser') renderReportTable();
        if (state.canViewAnalytics || state.role !== 'subuser') renderCharts();
        if (state.dashboardPage) {
            try {
                await loadDashboardStats(options.refreshStats === true);
            } catch (error) {
                if (state.dashboardPage === 'home') {
                    renderDashboardMiniMapFromProperties();
                }
            }
        }
        populateAssignedSubuserFilter();
        refreshMapCategoryFilters();
        if (state.missingCoordinatesStatsEndpoint && document.getElementById('missing-coordinates-summary')) {
            await loadMissingCoordinatesStats();
        }
    }

    async function loadMissingCoordinatesStats() {
        if (!state.missingCoordinatesStatsEndpoint) return;
        var payload = await api(state.missingCoordinatesStatsEndpoint);
        var stats = payload.stats || {};
        var totalBadge = document.getElementById('missing-coordinates-total-badge');
        var recoverableBadge = document.getElementById('missing-coordinates-recoverable-badge');
        var exhaustedBadge = document.getElementById('missing-coordinates-exhausted-badge');
        var summary = document.getElementById('missing-coordinates-summary');
        var adminBody = document.getElementById('missing-coordinates-admin-body');
        if (totalBadge) totalBadge.textContent = 'Totale: ' + Number(stats.total || 0);
        if (recoverableBadge) recoverableBadge.textContent = 'Recuperabili: ' + Number(stats.recoverable || 0);
        if (exhaustedBadge) exhaustedBadge.textContent = 'Esauriti: ' + Number(stats.exhausted || 0);
        if (summary) {
            summary.textContent = payload.scope === 'admin'
                ? 'Panoramica globale degli immobili senza coordinate sulla mappa.'
                : 'Immobili del tuo tenant ancora senza coordinate sulla mappa.';
        }
        if (adminBody) {
            var tenants = Array.isArray(payload.tenants) ? payload.tenants : [];
            adminBody.innerHTML = tenants.length
                ? tenants.map(function (tenant) {
                    var label = escapeHtml(String(tenant.tenant_name || ('Tenant #' + tenant.tenant_id)));
                    var email = tenant.tenant_email ? '<div class="text-muted small">' + escapeHtml(String(tenant.tenant_email)) + '</div>' : '';
                    return '<tr>'
                        + '<td>' + label + email + '</td>'
                        + '<td class="text-end">' + escapeHtml(String(tenant.total || 0)) + '</td>'
                        + '<td class="text-end">' + escapeHtml(String(tenant.recoverable || 0)) + '</td>'
                        + '<td class="text-end">' + escapeHtml(String(tenant.exhausted || 0)) + '</td>'
                        + '</tr>';
                }).join('')
                : '<tr><td colspan="4" class="text-center text-muted py-3 small">Nessun tenant con coordinate mancanti.</td></tr>';
        }
    }

    function captureMapView() {
        if (!state.map) return null;
        var center = state.map.getCenter();
        var zoom = state.map.getZoom();
        if (!center || !Number.isFinite(zoom)) return null;
        return {
            center: [Number(center.lat), Number(center.lng)],
            zoom: Number(zoom),
        };
    }

    function updateKpis() {
        var ownerCount = state.properties.reduce(function (sum, p) { return sum + (p.owners ? p.owners.length : 0); }, 0);
        var phoneCount = 0;
        if (state.canViewPhone) {
            phoneCount = state.properties.reduce(function (sum, p) {
                return sum + (p.owners || []).filter(function (o) { return o.telefono; }).length;
            }, 0);
        }
        var assignedCount = state.role === 'subuser'
            ? state.assignedProperties.length
            : state.properties.filter(function (p) { return (p.assignments || []).length > 0; }).length;

        function setKpi(key, value) {
            var el = document.querySelector('[data-kpi="' + key + '"]');
            if (el) el.textContent = value;
        }
        setKpi('properties', state.properties.length);
        setKpi('owners', ownerCount);
        if (state.canViewPhone) {
            setKpi('phones', phoneCount);
        } else {
            var phoneKpiEl = document.querySelector('[data-kpi="phones"]');
            if (phoneKpiEl) {
                var card = phoneKpiEl.closest('.card, .kpi-card, [class*="kpi"]');
                if (card) card.remove();
            }
        }
        setKpi('assigned', assignedCount);
    }

    function buildOwnerSummary(property) {
        return (property.owners || []).map(function (owner) {
            return ((owner.cognome || '') + ' ' + (owner.nome || '')).trim() || 'Intestatario';
        });
    }

    function buildOwnersTableHtml(property) {
        var owners = property.owners || [];
        if (!owners.length) return '<span class="text-muted small">Nessun intestatario</span>';
        return owners.map(function (owner) {
            var fullName = ((owner.cognome || '') + ' ' + (owner.nome || '')).trim() || 'Intestatario';
            var bornParts = [];
            if (owner.luogo_nascita) bornParts.push('Nato a: ' + owner.luogo_nascita);
            if (owner.data_nascita) bornParts.push('Nato il: ' + formatDobWithAge(owner.data_nascita));
            var ownershipParts = [];
            var quota = ownerQuotaLabel(owner, property);
            var titolarita = ownerTitolaritaLabel(owner, property);
            if (quota) ownershipParts.push(quota);
            if (titolarita) ownershipParts.push(titolarita);
            return '<div class="mb-1"><div>' + escapeHtml(fullName) + '</div>'
                + (ownershipParts.length ? '<div class="small text-muted">' + escapeHtml(ownershipParts.join(' · ')) + '</div>' : '')
                + (bornParts.length ? '<div class="small text-muted">' + escapeHtml(bornParts.join(' · ')) + '</div>' : '')
                + (propertyCanViewPhone(property) ? buildPhoneChips(owner.telefono) : '')
                + '</div>';
        }).join('');
    }

    function assignmentNames(property) {
        return (property.assignments || []).map(function (a) { return a.subuser_name; }).filter(Boolean);
    }

    function assignmentNamesLabel(property) {
        var names = assignmentNames(property);
        return names.length ? names.join(', ') : 'Non assegnato';
    }

    function buildAssignmentSummary(property) {
        var names = assignmentNames(property);
        var canManage = state.role !== 'subuser' && state.subusers.length > 0;
        var action = canManage
            ? '<button type="button" class="btn btn-outline-secondary btn-sm ms-1 assignment-picker-btn" data-property-id="' + property.id + '" title="Assegna/Modifica assegnazioni"><i class="bi bi-person-plus"></i></button>'
            : '';
        if (!names.length) return '<span class="small text-muted">Non assegnato</span>' + action;
        return '<span class="small">' + escapeHtml(names.join(', ')) + '</span>' + action;
    }

    function buildSelectOptions(selected) {
        return Object.keys(STATE_OPTIONS).map(function (value) {
            var label = STATE_OPTIONS[value];
            return '<option value="' + value + '"' + (value === selected ? ' selected' : '') + '>' + escapeHtml(label) + '</option>';
        }).join('');
    }

    function editableColumns(property) {
        var disabled = property.can_edit ? '' : 'disabled';
        return '<button type="button" class="btn btn-outline-primary btn-sm open-editor-modal" data-property-id="' + property.id + '" ' + disabled + '><i class="bi bi-pencil-square me-1"></i>Modifica</button>';
    }

    function deleteColumns(property) {
        if (!property.can_delete) {
            return '<span class="text-muted small">—</span>';
        }
        return '<button type="button" class="btn btn-outline-danger btn-sm delete-property-btn" data-property-id="' + property.id + '"><i class="bi bi-trash me-1"></i>Elimina</button>';
    }

    function detailColumn(property) {
        return '<button type="button" class="btn btn-outline-secondary btn-sm open-detail-modal" data-property-id="' + property.id + '"><i class="bi bi-eye me-1"></i>Dettaglio</button>';
    }

    function unitLabel(property) {
        var sub = property.subalterno ? '/' + property.subalterno : '';
        return 'F.' + (property.foglio || '—') + ' P.' + (property.particella || '—') + sub;
    }

    function buildTableData(properties, context) {
        context = context || 'default';
        return properties.map(function (property) {
            return {
                id: property.id,
                tenant: property.tenant_name || '',
                comune: property.comune || '',
                provincia: property.provincia || '',
                indirizzo: ((property.indirizzo || '') + ' ' + (property.civico || '')).trim(),
                unita: unitLabel(property),
                stato: STATE_OPTIONS[property.stato !== null && property.stato !== undefined ? property.stato : ''] || (property.stato || ''),
                colore: '<span class="color-dot" style="background:' + escapeHtml(property.colore_marker || '#0d6efd') + '"></span>',
                owners: buildOwnersTableHtml(property),
                assignmentsText: escapeHtml(assignmentNamesLabel(property)),
                assignments: buildAssignmentSummary(property),
                detail: detailColumn(property),
                editor: editableColumns(property),
                deleteAction: deleteColumns(property),
                raw: property,
            };
        });
    }

    function initDataTable(selector, rows, canExport, context) {
        context = context || 'default';
        if (state.tables[selector]) {
            state.tables[selector].destroy();
            $(selector).empty().append('<thead></thead><tfoot></tfoot><tbody></tbody>');
        }

        var reportColumns = [
            { title: 'Colore', data: 'colore' },
            { title: 'Comune', data: 'comune' },
            { title: 'Foglio/Particella/Sub', data: 'unita' },
            { title: 'Indirizzo', data: 'indirizzo' },
            { title: 'Intestatari', data: 'owners' },
            { title: 'Assegnati a', data: 'assignmentsText' },
            { title: 'Stato', data: 'stato' },
            { title: 'Dettaglio', data: 'detail' },
            { title: 'Modifica', data: 'editor' },
            { title: 'Elimina', data: 'deleteAction' },
        ];
        var assignedColumns = [
            { title: 'Colore', data: 'colore' },
            { title: 'Comune', data: 'comune' },
            { title: 'Foglio/Particella/Sub', data: 'unita' },
            { title: 'Indirizzo', data: 'indirizzo' },
            { title: 'Intestatari', data: 'owners' },
            { title: 'Assegnati a', data: 'assignments' },
            { title: 'Modifica', data: 'editor' },
            { title: 'Elimina', data: 'deleteAction' },
        ];
        var fullColumns = [
            { title: 'Tenant', data: 'tenant' },
            { title: 'Provincia', data: 'provincia' },
            { title: 'Comune', data: 'comune' },
            { title: 'Indirizzo', data: 'indirizzo' },
            { title: 'Foglio/Particella/Sub', data: 'unita' },
            { title: 'Stato', data: 'stato' },
            { title: 'Colore', data: 'colore' },
            { title: 'Intestatari', data: 'owners' },
            { title: 'Assegnazioni', data: 'assignments' },
            { title: 'Modifica', data: 'editor' },
            { title: 'Elimina', data: 'deleteAction' },
        ];
        var columns = context === 'report' ? reportColumns : (context === 'assigned' ? assignedColumns : fullColumns);
        var pageLength = (context === 'assigned' || context === 'report') ? 75 : 25;

        var theadHtml = '<tr>' + columns.map(function (c) { return '<th>' + c.title + '</th>'; }).join('') + '</tr>';
        var useFooterFilters = context !== 'report';
        var tfootHtml = '<tr>' + columns.map(function (c) {
            var skip = !useFooterFilters || c.title === 'Modifica' || c.title === 'Dettaglio' || c.title === 'Elimina';
            return '<th>' + (skip ? '' : '<input type="text" class="form-control form-control-sm" placeholder="' + escapeHtml(c.title) + '">') + '</th>';
        }).join('') + '</tr>';

        $(selector + ' thead').html(theadHtml);
        $(selector + ' tfoot').html(tfootHtml);

        var buttons = canExport ? [{ extend: 'csvHtml5', text: 'CSV' }, { extend: 'excelHtml5', text: 'Excel' }] : [];
        state.tables[selector] = $(selector).DataTable({
            data: rows,
            columns: columns,
            pageLength: pageLength,
            lengthMenu: context === 'assigned' || context === 'report' ? [25, 50, 75, 100, 150] : [25, 50, 100],
            order: [],
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/it-IT.json' },
            dom: buttons.length ? 'Bfrtip' : 'frtip',
            buttons: buttons,
            columnDefs: [{
                targets: columns.reduce(function (targets, column, index) {
                    if (['Colore', 'Dettaglio', 'Modifica', 'Elimina'].indexOf(column.title) !== -1) {
                        targets.push(index);
                    }
                    return targets;
                }, []),
                orderable: false
            }],
        });

        if (!useFooterFilters) return;

        state.tables[selector].columns().every(function (index) {
            var input = $(selector + ' tfoot th').eq(index).find('input');
            if (!input.length) return;
            input.on('keyup change clear', function () {
                if (state.tables[selector].column(index).search() !== input.val()) {
                    state.tables[selector].column(index).search(input.val()).draw();
                }
            });
        });
    }

    function getAssignedPropertiesForDisplay() {
        var el = document.getElementById('assigned-assignment-filter');
        var assignmentFilter = el ? el.value : 'all';
        if (assignmentFilter === 'assigned') return (state.assignedProperties || []).filter(function (p) { return p.is_assigned; });
        if (assignmentFilter === 'unassigned') return (state.assignedProperties || []).filter(function (p) { return !p.is_assigned; });
        return state.assignedProperties || [];
    }

    function renderAssignedTable() {
        if (!document.getElementById('assigned-table')) return;
        initDataTable('#assigned-table', buildTableData(getAssignedPropertiesForDisplay(), 'assigned'), state.canExport || state.role !== 'subuser', 'assigned');
    }

    function renderReportTable() {
        if (!document.getElementById('report-table')) return;
        initDataTable('#report-table', buildTableData(state.properties, 'report'), state.role !== 'subuser', 'report');
        hydrateReportFilters();
        if (state.reportQuery) {
            var comuneInput = document.getElementById('report-filter-comune');
            if (comuneInput && !comuneInput.value) {
                comuneInput.value = state.reportQuery;
            }
            if (state.tables['#report-table']) {
                state.tables['#report-table'].search(state.reportQuery).draw();
            }
        }
        applyReportFilters();
    }

    function hydrateReportFilters() {
        var colorSelect = document.getElementById('report-filter-color');
        var stateSelect = document.getElementById('report-filter-stato');
        if (colorSelect) {
            var selectedColor = colorSelect.value;
            var colors = [], seen = {};
            state.properties.forEach(function (p) { if (p.colore_marker && !seen[p.colore_marker]) { seen[p.colore_marker] = true; colors.push(p.colore_marker); } });
            colorSelect.innerHTML = '<option value="">Tutti</option>' + colors.map(function (c) {
                return colorOptionHtml(c, colorOptionLabel(c, 'Colore personalizzato ' + colorIdentifier(c)), false);
            }).join('');
            colorSelect.value = (selectedColor && seen[selectedColor]) ? selectedColor : '';
            updateColorSelectAppearance(colorSelect);
            updateReportFilterColorPreview(colorSelect.value);
        }
        if (stateSelect) {
            var selectedStato = stateSelect.value;
            stateSelect.innerHTML = '<option value="">Tutti</option>' + Object.keys(STATE_OPTIONS).map(function (v) { return '<option value="' + escapeHtml(STATE_OPTIONS[v]) + '">' + escapeHtml(STATE_OPTIONS[v]) + '</option>'; }).join('');
            stateSelect.value = selectedStato;
        }
    }

    function applyReportFilters() {
        var table = state.tables['#report-table'];
        if (!table) return;
        var colorValue    = (document.getElementById('report-filter-color')    || {}).value || '';
        var comuneValue   = (document.getElementById('report-filter-comune')   || {}).value || '';
        var foglioValue   = (document.getElementById('report-filter-foglio')   || {}).value || '';
        var statoValue    = (document.getElementById('report-filter-stato')    || {}).value || '';
        var assignedValue = (document.getElementById('report-filter-assigned') || {}).value || '';
        table.column(0).search(colorValue ? 'background:' + colorValue : '', true, false);
        table.column(1).search(comuneValue);
        table.column(2).search(foglioValue);
        table.column(5).search(assignedValue);
        table.column(6).search(statoValue);
        table.draw();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Raggruppa le properties per stesso subalterno catastale (foglio+particella+subalterno)
    // restituisce array di "gruppi": ogni gruppo ha una property principale (first)
    // con la lista owners unificata di tutte le properties del gruppo.
    // ─────────────────────────────────────────────────────────────────────────
    function groupPropertiesByUnit(properties) {
        var groups = {};
        var order  = [];

        for (var i = 0; i < properties.length; i++) {
            var p = properties[i];
            var provincia  = String(p.provincia  || '').trim().toUpperCase();
            var comune     = String(p.comune     || '').trim().toUpperCase();
            var codCatastale = String(p.cod_catastale || '').trim().toUpperCase();
            var sezione    = String(p.sezione    || '').trim().toUpperCase();
            var foglio     = String(p.foglio     || '').trim().toUpperCase();
            var particella = String(p.particella || '').trim().toUpperCase();
            var subalterno = String(p.subalterno || '').trim().toUpperCase();
            var comuneKey  = codCatastale !== '' ? ('COD:' + codCatastale) : ('COM:' + comune);
            var unitKey    = provincia + '|' + comuneKey + '|' + comune + '|' + sezione + '|' + foglio + '|' + particella + '|' + subalterno + '|' + String(p.user_id || '');

            if (!groups[unitKey]) {
                // Prima property del gruppo: diventa la "principale"
                groups[unitKey] = {
                    primary:    JSON.parse(JSON.stringify(p)), // copia profonda
                    properties: [],
                };
                order.push(unitKey);
            }
            groups[unitKey].properties.push(p);
        }

        // Per ogni gruppo, unifica gli owners e segna quali properties compongono il gruppo
        var result = [];
        for (var ki = 0; ki < order.length; ki++) {
            var key   = order[ki];
            var group = groups[key];
            var prim  = group.primary;

            // Unifica owners da tutte le properties del gruppo (evita duplicati per CF)
            var allOwners = [];
            var seenCf    = {};
            for (var pi = 0; pi < group.properties.length; pi++) {
                var owners = group.properties[pi].owners || [];
                for (var oi = 0; oi < owners.length; oi++) {
                    var ownerCf = owners[oi].codice_fiscale || ('__idx_' + allOwners.length);
                    if (!seenCf[ownerCf]) {
                        seenCf[ownerCf] = true;
                        var mergedOwner = Object.assign({}, owners[oi]);
                        mergedOwner.quota = mergedOwner.quota || group.properties[pi].quota || '';
                        mergedOwner.titolarita = mergedOwner.titolarita || group.properties[pi].titolarita || '';
                        allOwners.push(mergedOwner);
                    }
                }
            }
            prim.owners = allOwners;

            // Unifica assignments
            var allAssignments = [];
            var seenAss = {};
            for (var pi2 = 0; pi2 < group.properties.length; pi2++) {
                var assignments = group.properties[pi2].assignments || [];
                for (var ai = 0; ai < assignments.length; ai++) {
                    var assKey = String(assignments[ai].subuser_id);
                    if (!seenAss[assKey]) {
                        seenAss[assKey] = true;
                        allAssignments.push(assignments[ai]);
                    }
                }
            }
            prim.assignments = allAssignments;

            // Unifica notes
            var allNotes = [];
            for (var pi3 = 0; pi3 < group.properties.length; pi3++) {
                var notes = group.properties[pi3].notes || [];
                for (var ni = 0; ni < notes.length; ni++) {
                    allNotes.push(notes[ni]);
                }
            }
            prim.notes = allNotes;

            // Elenco degli id di tutte le properties nel gruppo (per uso futuro)
            prim._groupIds = group.properties.map(function (gp) { return gp.id; });

            result.push(prim);
        }

        return result;
    }

    function refreshMapCategoryFilters() {
        var container = document.getElementById('map-category-filter-panel');
        if (!container) return;
        var categories = state.properties.map(function (property) { return (property.categoria || '').trim(); }).filter(Boolean);
        categories = categories.filter(function (value, index) { return categories.indexOf(value) === index; }).sort();
        if (!categories.length) {
            state.mapCategoriaFilter = [];
            container.innerHTML = '';
            return;
        }

        var active = getCategorieFilter();
        if (active === null) {
            active = categories.slice();
            state.mapCategoriaFilter = active.slice();
        } else {
            active = active.filter(function (value) { return categories.indexOf(value) !== -1; });
            state.mapCategoriaFilter = active.slice();
        }

        container.innerHTML = '<strong class="me-1">Categoria:</strong>'
            + categories.map(function (category, index) {
                var safeId = 'filter-categoria-' + index;
                return '<div class="form-check form-check-inline me-0">'
                    + '<input class="form-check-input map-categoria-filter" type="checkbox" value="' + escapeHtml(category) + '" id="' + safeId + '"' + (active.indexOf(category) !== -1 ? ' checked' : '') + ' style="width:0.75rem;height:0.75rem;">'
                    + '<label class="form-check-label" for="' + safeId + '">' + escapeHtml(category) + '</label>'
                    + '</div>';
            }).join('')
            + '<button id="btn-select-all-categorie" class="btn btn-xs btn-outline-secondary" style="font-size:0.7rem;padding:0.1rem 0.4rem;margin-left:1rem;">Seleziona tutte</button>';
    }

    function renderMap() {
        var container = document.getElementById('map-fullpage') || document.getElementById('map-container');
        if (!container) return;

        if (!state.map) {
            state.map = L.map(container).setView([41.9, 12.5], 6);
            state.clusterIconCache = new Map();

            var layerStreets = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                maxZoom: 19,
            });
            var layerSatellite = L.tileLayer(
                'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
                { attribution: 'Tiles &copy; Esri', maxZoom: 19 }
            );
            layerStreets.addTo(state.map);
            L.control.layers({ 'Strade': layerStreets, 'Satellite': layerSatellite }, {}, { position: 'topright' }).addTo(state.map);

            state.markers = L.markerClusterGroup({
                spiderfyOnMaxZoom: true,
                zoomToBoundsOnClick: false,
                showCoverageOnHover: false,
                maxClusterRadius: 40,
                iconCreateFunction: function (cluster) {
                    var childMarkers = cluster.getAllChildMarkers();
                    var total = childMarkers.length;
                    if (!total) return L.divIcon({ html: '', className: 'analyticspro-cluster-icon', iconSize: L.point(40, 40) });
                    var counts = {};
                    for (var i = 0; i < childMarkers.length; i++) {
                        var opts  = childMarkers[i].options || {};
                        var color = opts.fillColor || opts.color || '#808080';
                        counts[color] = (counts[color] || 0) + 1;
                    }
                    var colorKeys = Object.keys(counts).sort();
                    var cacheKey  = total + '|' + colorKeys.map(function (c) { return c + ':' + counts[c]; }).join('|');
                    if (state.clusterIconCache && state.clusterIconCache.has(cacheKey)) return state.clusterIconCache.get(cacheKey);
                    var r = 1, startAngle = -Math.PI / 2, paths = '';
                    if (colorKeys.length === 1) {
                        var sc = /^#[0-9a-fA-F]{3,8}$/.test(colorKeys[0]) ? colorKeys[0] : '#808080';
                        paths = '<circle cx="0" cy="0" r="' + r + '" fill="' + sc + '"></circle>';
                    } else {
                        for (var j = 0; j < colorKeys.length; j++) {
                            var col = colorKeys[j], cnt = counts[col], pct = cnt / total;
                            var endAngle = startAngle + Math.PI * 2 * pct;
                            var safeCol  = /^#[0-9a-fA-F]{3,8}$/.test(col) ? col : '#808080';
                            var x1 = (Math.cos(startAngle) * r).toFixed(6), y1 = (Math.sin(startAngle) * r).toFixed(6);
                            var x2 = (Math.cos(endAngle)   * r).toFixed(6), y2 = (Math.sin(endAngle)   * r).toFixed(6);
                            var largeArc = pct > 0.5 ? 1 : 0;
                            paths += '<path d="M 0 0 L ' + x1 + ' ' + y1 + ' A ' + r + ' ' + r + ' 0 ' + largeArc + ' 1 ' + x2 + ' ' + y2 + ' Z" fill="' + safeCol + '"></path>';
                            startAngle = endAngle;
                        }
                    }
                    var svg = '<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="-1.15 -1.15 2.3 2.3">'
                        + '<circle cx="0" cy="0" r="1.12" fill="rgba(255,255,255,0.3)"></circle>'
                        + paths
                        + '<circle cx="0" cy="0" r="0.44" fill="rgba(255,255,255,0.92)"></circle>'
                        + '<text x="0" y="0.16" text-anchor="middle" dominant-baseline="middle" font-size="0.44" font-weight="700" font-family="sans-serif" fill="#222">' + total + '</text>'
                        + '</svg>';
                    var icon = L.divIcon({ html: svg, className: 'analyticspro-cluster-icon', iconSize: L.point(40, 40), iconAnchor: L.point(20, 20) });
                    if (state.clusterIconCache) state.clusterIconCache.set(cacheKey, icon);
                    return icon;
                },
            });

            state.markers.on('spiderfied', function () { state.map.closePopup(); });
            state.map.addLayer(state.markers);
            setCadastralLayerEnabled(state.cadastralLayerEnabled);
            window.setTimeout(function () {
                if (state.map) {
                    state.map.invalidateSize();
                }
            }, 60);
        }

        var statiFilter = getStatiFilter();
        var categorieFilter = getCategorieFilter();
        var pendingView = state.pendingMapView;
        var pendingNewMarkerFocus = state.pendingNewMarkerFocus;
        state.pendingMapView = null;
        state.pendingNewMarkerFocus = null;
        state.markers.clearLayers();
        var points = [];

        // ── Raggruppa per unità catastale (foglio+particella+subalterno) ──────
        var unitGroups = groupPropertiesByUnit(state.properties);

        for (var ui = 0; ui < unitGroups.length; ui++) {
            var property = unitGroups[ui];
            if (!property.lat || !property.lng) continue;
            var lat = Number(property.lat);
            var lng = Number(property.lng);
            if (!isFinite(lat) || !isFinite(lng)) continue;

            var statoVal = (property.stato !== null && property.stato !== undefined) ? String(property.stato) : '';
            if (statiFilter.indexOf(statoVal) === -1) continue;
            if (categorieFilter !== null && categorieFilter.indexOf(String(property.categoria || '').trim()) === -1) continue;

            var color  = property.colore_marker || '#2A519F';
            var marker = L.circleMarker([lat, lng], {
                radius: 9,
                color: color,
                fillColor: color,
                fillOpacity: 0.92,
                weight: 2,
            });
            marker._analyticsPropertyId = Number(property.id);
            marker._analyticsPropertyData = property;
            marker.bindPopup(buildPopupHtml(property), { maxWidth: 460 });
            state.markers.addLayer(marker);
            points.push(property);
        }

        if (pendingNewMarkerFocus) {
            if (!focusMapOnTargetMarker(pendingNewMarkerFocus)) {
                showMapFeedback('Marker salvato ma non trovato in mappa dopo il ricaricamento.', 'warning', 3200);
            }
        } else if (pendingView && pendingView.center && Number.isFinite(pendingView.zoom)) {
            state.map.setView(pendingView.center, pendingView.zoom, { animate: false });
        } else if (points.length) {
            state.map.fitBounds(state.markers.getBounds().pad(0.2));
        }
        scheduleMapInvalidateSize(120);
        window.setTimeout(function () {
            if (state.map) state.map.invalidateSize();
        }, 450);
    }

    window.addEventListener('analyticspro:topbar-resize', function () {
        scheduleMapInvalidateSize(120);
        if (state.dashboardMiniMap) {
            window.setTimeout(function () {
                if (state.dashboardMiniMap) state.dashboardMiniMap.invalidateSize();
            }, 120);
        }
    });
    window.addEventListener('analyticspro:layout-resize', function () {
        scheduleMapInvalidateSize(120);
        if (state.dashboardMiniMap) {
            window.setTimeout(function () {
                if (state.dashboardMiniMap) state.dashboardMiniMap.invalidateSize();
            }, 180);
        }
    });
    window.addEventListener('resize', function () {
        scheduleMapInvalidateSize(180);
    });

    // ─────────────────────────────────────────────────────────────────────────
    // Righe intestatari per il popup
    // ─────────────────────────────────────────────────────────────────────────
    function ownerDetailRows(property) {
        var owners = property.owners || [];
        if (!owners.length) return '<div class="text-muted small">Nessun intestatario disponibile.</div>';
        return owners.map(function (owner) {
            var fullName = ((owner.cognome || '') + ' ' + (owner.nome || '')).trim() || 'Intestatario';
            var identityParts = [];
            if (owner.codice_fiscale) identityParts.push('CF/P.IVA: ' + owner.codice_fiscale);
            if (owner.email)          identityParts.push('\u2709 ' + owner.email);
            if (owner.indirizzo)      identityParts.push('\uD83D\uDCCD ' + owner.indirizzo);
            var profileParts = [];
            if (owner.luogo_nascita)                 profileParts.push('Nato a: ' + owner.luogo_nascita);
            if (owner.data_nascita)                   profileParts.push('Nato il: ' + formatDobWithAge(owner.data_nascita));
            if (owner.genere)                         profileParts.push('Genere: ' + owner.genere);
            var ownershipParts = [];
            var quota = ownerQuotaLabel(owner, property);
            var titolarita = ownerTitolaritaLabel(owner, property);
            if (quota) ownershipParts.push(quota);
            if (titolarita) ownershipParts.push(titolarita);
            return '<div class="property-owner-row">'
                + '<div class="fw-semibold">' + escapeHtml(fullName) + '</div>'
                + (ownershipParts.length ? '<div class="owner-meta">' + escapeHtml(ownershipParts.join(' · ')) + '</div>' : '')
                + (identityParts.length ? '<div class="owner-meta">' + escapeHtml(identityParts.join(' | ')) + '</div>' : '')
                + (profileParts.length  ? '<div class="owner-meta">' + escapeHtml(profileParts.join(' | '))  + '</div>' : '')
                + (propertyCanViewPhone(property) && owner.telefono ? buildPhoneChips(owner.telefono) : '')
                + '</div>';
        }).join('');
    }

    // Nomi brevi degli owners per il titolo header
    function ownerNamesShort(property) {
        var owners = property.owners || [];
        if (!owners.length) return '';
        return owners.map(function (o) {
            return ((o.cognome || '') + ' ' + (o.nome || '')).trim() || 'Intestatario';
        }).join(', ');
    }

    function propertyNotesHtml(property) {
        var notes = property.notes || [];
        if (!notes.length) return '<span class="text-muted small">Nessuna nota</span>';
        return notes.map(function (note) {
            return noteLogLine(note);
        }).filter(Boolean).map(function (line) {
            return '<div class="small mb-1">' + escapeHtml(line) + '</div>';
        }).join('');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Card HTML professionale del popup — header con dati immobile
    // ─────────────────────────────────────────────────────────────────────────
    function buildPropertyCardHtml(property, options) {
        options = options || {};
        var mapMode      = options.mapMode === true;
        var addressLabel = ((property.indirizzo || '') + ' ' + (property.civico || '')).trim() || 'Immobile';
        var aNames         = assignmentNames(property);
        var assignmentText = aNames.length ? escapeHtml(aNames.join(', ')) : '<span class="text-muted">Non assegnato</span>';
        var assignmentBtn  = (state.role !== 'subuser' && state.subusers.length)
            ? '<button type="button" class="btn btn-outline-secondary btn-sm assignment-picker-btn" data-property-id="' + property.id + '" title="Assegna/Modifica assegnazioni"><i class="bi bi-person-plus"></i></button>'
            : '';
        var editAction = property.can_edit
            ? '<button type="button" class="btn btn-outline-primary btn-sm open-editor-modal" data-property-id="' + property.id + '"><i class="bi bi-pencil-square me-1"></i>Modifica</button>'
            : '<div class="small text-warning-emphasis">\u26A0\uFE0F Non hai i permessi per modificare questo marker.</div>';
        var deleteAction = property.can_delete
            ? '<button type="button" class="btn btn-outline-danger btn-sm delete-property-btn" data-property-id="' + property.id + '"><i class="bi bi-trash me-1"></i>Elimina</button>'
            : '';
        var closeAction = mapMode
            ? '<button type="button" class="btn btn-outline-secondary btn-sm close-map-popup"><i class="bi bi-x-lg me-1"></i>Chiudi</button>'
            : '<button type="button" class="btn btn-outline-secondary btn-sm close-detail-modal"><i class="bi bi-x-lg me-1"></i>Chiudi</button>';
        var statoLabel = property.stato ? (STATE_OPTIONS[property.stato] || property.stato) : '';
        var statoBadge = statoLabel
            ? '<span class="badge ms-1" style="background:' + escapeHtml(property.colore_marker || '#0d6efd') + ';color:#fff;font-size:.65rem;">' + escapeHtml(statoLabel) + '</span>'
            : '';
        var headerFacts = propertyHeaderFacts(property).map(function (item) {
            return '<span class="me-3"><strong>' + escapeHtml(item.label) + ':</strong> ' + escapeHtml(item.value) + '</span>';
        }).join('');

        return '<div class="card map-popup-card mb-2" data-property-id="' + property.id + '">'
            + '<div class="card-header py-2 px-3">'
            + '<div class="fw-semibold">\uD83C\uDFE0 ' + escapeHtml(addressLabel) + ' \u2013 ' + escapeHtml(unitLabel(property)) + '</div>'
            + '<div class="small mt-1">'
            + headerFacts
            + '</div>'
            + '<div class="small mt-1">'
            + '<span class="color-dot me-1" style="background:' + escapeHtml(property.colore_marker || '#0d6efd') + '"></span>'
            + escapeHtml(property.comune || '')
            + statoBadge
            + '</div>'
            + '</div>'
            // ── BODY ──────────────────────────────────────────────────────
            + '<div class="card-body py-2 px-3">'
            + '<div class="popup-section-label">Intestatari</div>'
            + ownerDetailRows(property)
            + '<hr class="my-2">'
            + '<div class="popup-section-label">Note</div>'
            + '<div class="mt-1">' + propertyNotesHtml(property) + '</div>'
            + '<hr class="my-2">'
            + '<div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">'
            + '<div class="small"><strong>Assegnati a:</strong> ' + assignmentText + '</div>'
            + assignmentBtn
            + '</div>'
            + '</div>'
            // ── FOOTER ────────────────────────────────────────────────────
            + '<div class="card-footer py-2 px-3 d-flex align-items-center justify-content-between gap-2 flex-wrap">'
            + '<div class="d-flex align-items-center gap-2 flex-wrap">' + editAction + deleteAction + '</div>' + closeAction
            + '</div>'
            + '</div>';
    }

    function buildPopupHtml(property) {
        return '<div class="map-popup-wrapper">' + buildPropertyCardHtml(property, { mapMode: true }) + '</div>';
    }

    function destroyCharts() {
        Object.keys(state.charts).forEach(function (k) { state.charts[k].destroy(); });
        state.charts = {};
    }

    function chartColors(count) {
        if (window.analyticsproChartTheme && typeof window.analyticsproChartTheme.seriesColors === 'function') {
            return window.analyticsproChartTheme.seriesColors(count);
        }
        return ['#2A519F', '#f28e0e', '#12b76a', '#0ba5ec', '#7a5af8', '#f97066'].slice(0, count);
    }

    function pieChart(id, labels, data, options) {
        options = options || {};
        var ctx = document.getElementById(id);
        if (!ctx) return;
        state.charts[id] = new Chart(ctx, {
            type: options.type || 'doughnut',
            data: {
                labels: labels,
                datasets: [{
                    data: data,
                    backgroundColor: chartColors(data.length),
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                cutout: options.type === 'pie' ? 0 : '62%',
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    }

    function barChart(id, labels, data, label, options) {
        options = options || {};
        var ctx = document.getElementById(id);
        if (!ctx) return;
        state.charts[id] = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    label: label,
                    data: data,
                    backgroundColor: options.backgroundColor || chartColors(1)[0]
                }]
            },
            options: {
                indexAxis: options.horizontal ? 'y' : 'x',
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { grid: { display: !options.horizontal } },
                    y: { grid: { display: options.horizontal ? false : true } }
                }
            }
        });
    }

    function lineSparkline(id, values, color) {
        var ctx = document.getElementById(id);
        if (!ctx) return;
        var key = 'spark:' + id;
        if (state.charts[key]) {
            state.charts[key].destroy();
        }
        state.charts[key] = new Chart(ctx, {
            type: 'line',
            data: {
                labels: values.map(function (_, index) { return index + 1; }),
                datasets: [{
                    data: values,
                    borderColor: color || chartColors(1)[0],
                    backgroundColor: 'transparent',
                    fill: false
                }]
            },
            options: {
                plugins: { legend: { display: false }, tooltip: { enabled: false } },
                scales: { x: { display: false }, y: { display: false } },
                elements: { point: { radius: 0 } }
            }
        });
    }

    function setDashboardKpiValue(key, value, delta, sparkline) {
        var el = document.querySelector('[data-kpi="' + key + '"]');
        if (el) el.textContent = Number(value || 0).toLocaleString('it-IT');
        var sparkId = 'spark-' + key;
        if (Array.isArray(sparkline) && sparkline.length && document.getElementById(sparkId)) {
            lineSparkline(sparkId, sparkline, chartColors(1)[0]);
        }
    }

    function renderDashboardMiniMap(points) {
        var container = document.getElementById('dashboard-mini-map');
        if (!container || !window.L) return;
        if (!state.dashboardMiniMap) {
            state.dashboardMiniMap = L.map(container, { zoomControl: true, attributionControl: false });
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(state.dashboardMiniMap);
            state.dashboardMiniMarkers = L.markerClusterGroup();
            state.dashboardMiniMap.addLayer(state.dashboardMiniMarkers);
        }
        state.dashboardMiniMarkers.clearLayers();
        var bounds = [];
        (points || []).forEach(function (point) {
            if (!Number.isFinite(Number(point.lat)) || !Number.isFinite(Number(point.lng))) return;
            var marker = L.circleMarker([Number(point.lat), Number(point.lng)], {
                radius: 7,
                weight: 2,
                color: '#fff',
                fillColor: '#2A519F',
                fillOpacity: 0.85
            });
            var label = [point.comune || '', point.provincia ? '(' + point.provincia + ')' : '', point.categoria || ''].filter(Boolean).join(' · ');
            if (label) marker.bindPopup(escapeHtml(label));
            state.dashboardMiniMarkers.addLayer(marker);
            bounds.push([Number(point.lat), Number(point.lng)]);
        });
        if (bounds.length) {
            state.dashboardMiniMap.fitBounds(bounds, { padding: [24, 24] });
        } else {
            state.dashboardMiniMap.setView([41.9028, 12.4964], 5);
        }
        window.setTimeout(function () {
            if (state.dashboardMiniMap) state.dashboardMiniMap.invalidateSize();
        }, 300);
    }

    function renderDashboardMiniMapFromProperties() {
        var points = (state.properties || []).filter(function (property) {
            return Number.isFinite(Number(property.lat)) && Number.isFinite(Number(property.lng));
        }).slice(0, 250).map(function (property) {
            return {
                lat: Number(property.lat),
                lng: Number(property.lng),
                comune: property.comune || '',
                provincia: property.provincia || '',
                categoria: property.categoria || ''
            };
        });
        renderDashboardMiniMap(points);
    }

    function renderDashboardStats(stats) {
        var kpis = stats.kpis || {};
        renderAggregatedCharts(stats.series || {});
        ['properties', 'owners', 'phones', 'assigned'].forEach(function (key) {
            if (kpis[key]) {
                setDashboardKpiValue(key, kpis[key].value, kpis[key].delta, kpis[key].sparkline);
            }
        });
        if (state.dashboardPage === 'home') {
            renderDashboardMiniMap(stats.map_points || []);
        }
    }

    function renderAggregatedCharts(series) {
        destroyCharts();
        if (series.contacts && (series.contacts.labels || []).length) {
            pieChart('chart-contacts', series.contacts.labels || [], series.contacts.values || [], { type: 'doughnut' });
        }
        if (series.gender && (series.gender.labels || []).length) {
            pieChart('chart-gender', series.gender.labels || [], series.gender.values || [], { type: 'doughnut' });
        }
        if (series.age && (series.age.labels || []).length) {
            barChart('chart-age', series.age.labels || [], series.age.values || [], 'Intestatari');
        }
        if (series.comune_distribution && (series.comune_distribution.labels || []).length) {
            pieChart('chart-comune-distribution', series.comune_distribution.labels || [], series.comune_distribution.values || [], { type: 'doughnut' });
        }
        if (series.comune && (series.comune.labels || []).length) {
            barChart('chart-comune', series.comune.labels || [], series.comune.values || [], 'Immobili', { horizontal: true, backgroundColor: chartColors(2)[1] });
        }
        if (series.categoria && (series.categoria.labels || []).length) {
            pieChart('chart-categoria', series.categoria.labels || [], series.categoria.values || [], { type: 'doughnut' });
        }
        if (series.titolarita && (series.titolarita.labels || []).length) {
            pieChart('chart-titolarita', series.titolarita.labels || [], series.titolarita.values || [], { type: 'doughnut' });
        }
    }

    function renderCharts() {
        destroyCharts();
        var owners = [];
        state.properties.forEach(function (p) { (p.owners || []).forEach(function (o) { owners.push(o); }); });
        var withPhone = 0;
        if (state.canViewPhone) withPhone = owners.filter(function (o) { return o.telefono; }).length;
        var withEmail = owners.filter(function (o) { return o.email; }).length;
        var withPiva  = owners.filter(function (o) { return o.tipo === 'azienda'; }).length;
        var genders = {}, comuni = {}, categories = {}, ownership = {}, ages = {};
        owners.forEach(function (o) { var k = o.genere || 'N/D'; genders[k] = (genders[k] || 0) + 1; });
        state.properties.forEach(function (p) {
            var kc = p.comune || 'N/D'; comuni[kc] = (comuni[kc] || 0) + 1;
            var kcat = p.categoria || 'N/D'; categories[kcat] = (categories[kcat] || 0) + 1;
            var ko = p.titolarita || 'N/D'; ownership[ko] = (ownership[ko] || 0) + 1;
        });
        owners.forEach(function (o) { var k = ageGroup(calcAge(o.data_nascita)); ages[k] = (ages[k] || 0) + 1; });

        function setKpiAnalytics(key, value) {
            var el = document.querySelector('[data-kpi-analytics="' + key + '"]');
            if (el) el.textContent = value.toLocaleString('it-IT');
        }
        setKpiAnalytics('total', owners.length);
        if (state.canViewPhone) {
            setKpiAnalytics('phone', withPhone);
        } else {
            var phoneKpiEl = document.querySelector('[data-kpi-analytics="phone"]');
            if (phoneKpiEl) { var card = phoneKpiEl.closest('.card, .kpi-card, [class*="kpi"]'); if (card) card.remove(); }
        }
        setKpiAnalytics('email', withEmail);
        setKpiAnalytics('piva',  withPiva);

        if (state.canViewPhone) {
            pieChart('chart-contacts', ['Con telefono', 'Con email', 'Senza contatti'], [withPhone, withEmail, Math.max(owners.length - Math.max(withPhone, withEmail), 0)]);
        } else {
            pieChart('chart-contacts', ['Con email', 'Senza contatti'], [withEmail, Math.max(owners.length - withEmail, 0)]);
        }
        pieChart('chart-gender',    Object.keys(genders),    Object.keys(genders).map(function(k){return genders[k];}));
        barChart('chart-age',       Object.keys(ages),       Object.keys(ages).map(function(k){return ages[k];}), 'Intestatari');
        var sortedComuni = Object.keys(comuni).sort(function (a, b) { return (comuni[b] || 0) - (comuni[a] || 0); });
        var topComuni = sortedComuni.slice(0, 10);
        var distributionLimit = 20;
        var distributionLabels = sortedComuni.slice(0, distributionLimit);
        var distributionValues = distributionLabels.map(function (label) { return comuni[label]; });
        var remainingLabels = sortedComuni.slice(distributionLimit);
        if (remainingLabels.length) {
            var othersValue = remainingLabels.reduce(function (sum, label) { return sum + (comuni[label] || 0); }, 0);
            if (othersValue > 0) {
                distributionLabels.push('Altri');
                distributionValues.push(othersValue);
            }
        }
        pieChart('chart-comune-distribution', distributionLabels, distributionValues);
        barChart('chart-comune', topComuni, topComuni.map(function(k){return comuni[k];}), 'Immobili', { horizontal: true, backgroundColor: chartColors(2)[1] });
        pieChart('chart-categoria', Object.keys(categories), Object.keys(categories).map(function(k){return categories[k];}));
        pieChart('chart-titolarita',Object.keys(ownership),  Object.keys(ownership).map(function(k){return ownership[k];}));
        if (state.dashboardPage === 'home') {
            renderDashboardMiniMapFromProperties();
        }
    }

    function populateAssignedSubuserFilter() {
        var select = document.getElementById('assigned-subuser-filter');
        if (!select) return;
        select.innerHTML = '<option value="">' + (state.role === 'subuser' ? 'Le mie assegnazioni' : 'Tutte le assegnazioni') + '</option>'
            + state.subusers.map(function (s) { return '<option value="' + s.id + '">' + escapeHtml(s.nome + ' ' + s.cognome) + '</option>'; }).join('');
    }

    function findPropertyById(propertyId) {
        var id = Number(propertyId);
        for (var i = 0; i < state.properties.length; i++) { if (Number(state.properties[i].id) === id) return state.properties[i]; }
        var assigned = state.assignedProperties || [];
        for (var j = 0; j < assigned.length; j++) { if (Number(assigned[j].id) === id) return assigned[j]; }
        return null;
    }

    async function savePropertyPayload(payload) {
        await api(state.propertyUpdateEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(Object.assign({ csrf_token: state.csrfToken }, payload)),
        });
        await loadProperties({ preserveMapView: true });
    }

    async function removeOwnerPhone(propertyId, ownerId, phone) {
        return api(state.propertyUpdateEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: state.csrfToken,
                action: 'remove_owner_phone',
                property_id: propertyId,
                owner_id: ownerId,
                phone: phone
            }),
        });
    }

    async function addOwnerPhone(propertyId, ownerId, phone) {
        return api(state.propertyUpdateEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: state.csrfToken,
                action: 'add_owner_phone',
                property_id: propertyId,
                owner_id: ownerId,
                phone: phone
            }),
        });
    }

    function applyOwnerPhoneUpdate(propertyId, ownerId, updatedPhoneRaw) {
        var seenRefs = typeof WeakSet === 'function' ? new WeakSet() : null;
        [state.properties, state.assignedProperties || []].forEach(function (collection) {
            (collection || []).forEach(function (property) {
                if (seenRefs && seenRefs.has(property)) return;
                if (seenRefs) seenRefs.add(property);
                if (Number(property.id) !== Number(propertyId)) return;
                (property.owners || []).forEach(function (owner) {
                    if (Number(owner.id) === Number(ownerId)) {
                        owner.telefono = updatedPhoneRaw || '';
                    }
                });
            });
        });
        updateKpis();
        renderAssignedTable();
        if (state.canViewReports || state.role !== 'subuser') renderReportTable();
        if (state.canViewAnalytics || state.role !== 'subuser') renderCharts();
        refreshOpenPropertyViews(propertyId);
    }

    function refreshOpenPropertyViews(propertyId) {
        var property = findPropertyById(propertyId);
        if (!property) return;

        var detailModal = document.getElementById('property-detail-modal');
        var detailContent = document.getElementById('property-detail-content');
        if (detailModal && detailModal.classList.contains('show') && Number(detailModal.dataset.propertyId || 0) === Number(propertyId) && detailContent) {
            detailContent.innerHTML = buildPropertyCardHtml(property, { mapMode: false });
        }

        if (state.markers && typeof state.markers.eachLayer === 'function') {
            state.markers.eachLayer(function (layer) {
                if (Number(layer._analyticsPropertyId || 0) !== Number(propertyId)) return;
                if (typeof layer.setPopupContent === 'function') {
                    layer.setPopupContent(buildPopupHtml(property));
                }
            });
        }
    }

    function normalizeFocusToken(value) {
        return String(value === null || value === undefined ? '' : value).trim().toUpperCase();
    }

    function coordinatesMatch(latA, lngA, latB, lngB) {
        if (!Number.isFinite(latA) || !Number.isFinite(lngA) || !Number.isFinite(latB) || !Number.isFinite(lngB)) {
            return false;
        }
        return Math.abs(latA - latB) <= 0.000001 && Math.abs(lngA - lngB) <= 0.000001;
    }

    function propertyMatchesFocusTarget(property, target) {
        if (!property || !target) return false;
        var targetLat = parseFiniteCoordinate(target.lat);
        var targetLng = parseFiniteCoordinate(target.lng);
        var propertyLat = parseFiniteCoordinate(property.lat);
        var propertyLng = parseFiniteCoordinate(property.lng);
        if (coordinatesMatch(propertyLat, propertyLng, targetLat, targetLng)) {
            return true;
        }
        return normalizeFocusToken(property.provincia) === normalizeFocusToken(target.provincia)
            && normalizeFocusToken(property.comune) === normalizeFocusToken(target.comune)
            && normalizeFocusToken(property.cod_catastale) === normalizeFocusToken(target.cod_catastale)
            && normalizeFocusToken(property.sezione) === normalizeFocusToken(target.sezione)
            && normalizeFocusToken(property.foglio) === normalizeFocusToken(target.foglio)
            && normalizeFocusToken(property.particella) === normalizeFocusToken(target.particella)
            && normalizeFocusToken(property.subalterno) === normalizeFocusToken(target.subalterno);
    }

    function focusMapOnTargetMarker(target) {
        if (!state.map || !state.markers || !target) return false;
        var targetMarker = null;
        state.markers.eachLayer(function (layer) {
            if (targetMarker) return;
            if (!layer || !layer._analyticsPropertyData) return;
            if (propertyMatchesFocusTarget(layer._analyticsPropertyData, target)) {
                targetMarker = layer;
            }
        });
        if (!targetMarker || typeof targetMarker.getLatLng !== 'function') {
            return false;
        }
        var markerLatLng = targetMarker.getLatLng();
        state.map.setView(markerLatLng, 18, { animate: false });
        if (typeof state.markers.zoomToShowLayer === 'function') {
            state.markers.zoomToShowLayer(targetMarker, function () {
                targetMarker.openPopup();
            });
        } else {
            targetMarker.openPopup();
        }
        return true;
    }

    async function deleteProperty(propertyId) {
        if (!state.propertyDeleteEndpoint) throw new Error('Endpoint eliminazione non configurato.');
        await api(state.propertyDeleteEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: state.csrfToken, property_id: propertyId })
        });
        await loadProperties();
    }

    function manualRecordFormData() {
        var form = document.getElementById('manual-record-form');
        if (!form) return {};
        var data = {};
        Array.from(form.elements).forEach(function (field) {
            if (!field.name) return;
            if (field.name.indexOf('__manual_record_locked__') === 0) return;
            data[field.name] = String(field.value || '').trim();
        });
        return data;
    }

    function manualRecordFormHasValues() {
        var data = manualRecordFormData();
        return Object.keys(data).some(function (key) { return data[key] !== ''; });
    }

    function manualRecordHiddenMirrorName(name) {
        return name ? ('__manual_record_locked__' + name) : '';
    }

    function removeManualRecordHiddenMirror(form, field) {
        if (!form || !field || !field.name) return;
        var mirror = Array.from(form.querySelectorAll('input[type="hidden"][data-manual-record-mirror-for]')).find(function (item) {
            return item.dataset.manualRecordMirrorFor === field.name;
        });
        if (mirror) mirror.remove();
    }

    function ensureManualRecordHiddenMirror(form, field) {
        if (!form || !field || !field.name) return null;
        var mirror = Array.from(form.querySelectorAll('input[type="hidden"][data-manual-record-mirror-for]')).find(function (item) {
            return item.dataset.manualRecordMirrorFor === field.name;
        });
        if (!mirror) {
            mirror = document.createElement('input');
            mirror.type = 'hidden';
            mirror.name = manualRecordHiddenMirrorName(field.name);
            mirror.dataset.manualRecordMirrorFor = field.name;
            form.appendChild(mirror);
        }
        mirror.value = field.value || '';
        return mirror;
    }

    function setManualRecordFieldLocked(form, field, shouldLock) {
        if (!field) return;
        field.classList.toggle('readonly', shouldLock);
        field.classList.toggle('bg-light', shouldLock);
        field.dataset.autoFilled = shouldLock ? '1' : '0';
        field.title = shouldLock ? MANUAL_RECORD_AUTOFILL_TITLE : '';
        if (shouldLock) {
            field.setAttribute('aria-label', (field.getAttribute('aria-label') || field.name || 'Campo') + ' · ' + MANUAL_RECORD_AUTOFILL_TITLE);
        } else if (field.hasAttribute('aria-label') && field.getAttribute('aria-label').indexOf(' · ' + MANUAL_RECORD_AUTOFILL_TITLE) !== -1) {
            field.setAttribute('aria-label', field.getAttribute('aria-label').replace(' · ' + MANUAL_RECORD_AUTOFILL_TITLE, ''));
        }
        if (field.tagName === 'SELECT') {
            field.disabled = shouldLock;
            if (shouldLock) {
                ensureManualRecordHiddenMirror(form, field);
            } else {
                removeManualRecordHiddenMirror(form, field);
            }
            return;
        }
        field.readOnly = shouldLock;
    }

    function syncManualRecordAutofillHint(form) {
        var hint = document.getElementById('manual-record-autofill-hint');
        if (!hint) return;
        var hasLockedFields = !!form && Array.from(form.querySelectorAll('.manual-record-lockable')).some(function (field) {
            return field.dataset.autoFilled === '1';
        });
        hint.classList.toggle('d-none', !hasLockedFields);
    }

    function resetManualRecordForm() {
        var form = document.getElementById('manual-record-form');
        var feedback = document.getElementById('manual-record-feedback');
        var coordinateSummary = document.getElementById('manual-record-coordinates-summary');
        if (form) form.reset();
        if (form) {
            Array.from(form.querySelectorAll('input[type="hidden"][data-manual-record-mirror-for]')).forEach(function (field) {
                field.remove();
            });
        }
        Array.from(document.querySelectorAll('.manual-record-lockable')).forEach(function (field) {
            setManualRecordFieldLocked(form, field, false);
        });
        syncManualRecordAutofillHint(form);
        if (feedback) {
            feedback.className = 'alert d-none py-2';
            feedback.textContent = '';
        }
        if (coordinateSummary) {
            coordinateSummary.className = 'col-12 small text-muted d-none';
            coordinateSummary.textContent = '';
        }
        state.manualRecordContext = null;
    }

    function setManualRecordFieldValue(form, name, value) {
        if (!form) return;
        var field = form.elements.namedItem(name);
        if (!field) return;
        field.value = value === null || value === undefined ? '' : String(value);
        if (field.tagName === 'SELECT' && field.disabled) {
            ensureManualRecordHiddenMirror(form, field);
        }
    }

    function prefillManualRecordForm(values, lockedFields) {
        var form = document.getElementById('manual-record-form');
        if (!form) return;
        resetManualRecordForm();
        Object.keys(values || {}).forEach(function (key) {
            setManualRecordFieldValue(form, key, values[key]);
        });
        Array.from(document.querySelectorAll('.manual-record-lockable')).forEach(function (field) {
            var shouldLock = Array.isArray(lockedFields) && lockedFields.indexOf(field.name) !== -1;
            setManualRecordFieldLocked(form, field, shouldLock);
        });
        syncManualRecordAutofillHint(form);
    }

    function syncManualRecordCoordinateSummary(values, context) {
        var summary = document.getElementById('manual-record-coordinates-summary');
        if (!summary) return;
        var fromMap = !!(context && context.fromMap);
        var lat = parseFiniteCoordinate(values && values.Latitudine);
        var lng = parseFiniteCoordinate(values && values.Longitudine);
        if (!fromMap || lat === null || lng === null) {
            summary.className = 'col-12 small text-muted d-none';
            summary.textContent = '';
            return;
        }
        summary.className = 'col-12 small text-muted';
        summary.textContent = 'Coordinate: ' + formatCoordinateSummary(lat, lng);
    }

    function openManualRecordModal(values, lockedFields, context) {
        var modalEl = document.getElementById('manual-record-modal');
        if (!modalEl) return;
        var effectiveValues = values || {};
        var effectiveContext = context && typeof context === 'object' ? context : {};
        prefillManualRecordForm(effectiveValues, lockedFields || []);
        state.manualRecordContext = {
            fromMap: !!effectiveContext.fromMap,
            lat: parseFiniteCoordinate(effectiveValues.Latitudine),
            lng: parseFiniteCoordinate(effectiveValues.Longitudine),
        };
        syncManualRecordCoordinateSummary(effectiveValues, state.manualRecordContext);
        state.manualRecordModal = bootstrap.Modal.getOrCreateInstance(modalEl);
        state.manualRecordModal.show();
    }

    function setManualRecordFeedback(message, type) {
        var feedback = document.getElementById('manual-record-feedback');
        if (!feedback) return;
        if (!message) {
            feedback.className = 'alert d-none py-2';
            feedback.textContent = '';
            return;
        }
        feedback.className = 'alert alert-' + (type || 'warning') + ' py-2';
        feedback.textContent = message;
    }

    function ensureValueLabel(value) {
        var normalized = String(value === null || value === undefined ? '' : value).trim();
        return normalized !== '' ? normalized : '—';
    }

    function buildSavedMarkerSummary(values) {
        var comune = ensureValueLabel(values.Comune);
        var provincia = ensureValueLabel(values.Provincia);
        var foglio = ensureValueLabel(values.Foglio);
        var particella = ensureValueLabel(values.Particella);
        var subalterno = ensureValueLabel(values.Subalterno);
        var indirizzo = ensureValueLabel(((values.Indirizzo || '') + ' ' + (values.Civico || '')).trim());
        var lat = parseFiniteCoordinate(values.Latitudine);
        var lng = parseFiniteCoordinate(values.Longitudine);
        return {
            comuneProvincia: comune + ' (' + provincia + ')',
            catastale: 'F.' + foglio + ' · P.' + particella + ' · Sub. ' + subalterno,
            indirizzo: indirizzo,
            coordinate: lat !== null && lng !== null ? formatCoordinateSummary(lat, lng) : '—',
        };
    }

    function showManualRecordSavedModal(values) {
        var modalEl = document.getElementById('manual-record-saved-modal');
        var bodyEl = document.getElementById('manual-record-saved-body');
        if (!modalEl || !bodyEl) return;
        var summary = buildSavedMarkerSummary(values || {});
        bodyEl.innerHTML = '<ul class="list-unstyled small mb-0">'
            + '<li><strong>Comune/Provincia:</strong> ' + escapeHtml(summary.comuneProvincia) + '</li>'
            + '<li><strong>Foglio/Particella/Subalterno:</strong> ' + escapeHtml(summary.catastale) + '</li>'
            + '<li><strong>Indirizzo:</strong> ' + escapeHtml(summary.indirizzo) + '</li>'
            + '<li><strong>Coordinate:</strong> ' + escapeHtml(summary.coordinate) + '</li>'
            + '</ul>';
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    function initManualRecordModal() {
        var modalEl = document.getElementById('manual-record-modal');
        var openBtn = document.getElementById('open-manual-record-modal');
        var saveBtn = document.getElementById('save-manual-record-btn');
        if (!modalEl || !saveBtn) return;
        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        state.manualRecordModal = modal;
        var allowClose = false;
        if (openBtn) {
            openBtn.addEventListener('click', function () {
                allowClose = false;
                openManualRecordModal({}, [], { fromMap: false });
            });
        }
        modalEl.addEventListener('hide.bs.modal', function (event) {
            if (allowClose || !manualRecordFormHasValues()) return;
            if (!window.confirm('Ci sono dati non salvati. Vuoi davvero chiudere il modulo?')) {
                event.preventDefault();
                return;
            }
            allowClose = true;
        });
        modalEl.addEventListener('hidden.bs.modal', function () {
            if (allowClose) {
                resetManualRecordForm();
                allowClose = false;
            }
        });
        saveBtn.addEventListener('click', async function () {
            var feedback = document.getElementById('manual-record-feedback');
            var row = manualRecordFormData();
            var manualContext = state.manualRecordContext || { fromMap: false };
            if (manualContext.fromMap) {
                var mapLat = parseFiniteCoordinate(row.Latitudine);
                var mapLng = parseFiniteCoordinate(row.Longitudine);
                if (mapLat === null || mapLng === null) {
                    setManualRecordFeedback('Impossibile salvare: coordinate mappa mancanti o non valide. Chiudi e ripeti il click sulla mappa.', 'danger');
                    return;
                }
                row.Latitudine = String(mapLat);
                row.Longitudine = String(mapLng);
            }
            if (!Object.keys(row).some(function (key) { return row[key] !== ''; })) {
                if (feedback) {
                    feedback.className = 'alert alert-warning py-2';
                    feedback.textContent = 'Compila almeno un campo prima di salvare.';
                }
                return;
            }
            saveBtn.disabled = true;
            if (feedback) {
                feedback.className = 'alert d-none py-2';
                feedback.textContent = '';
            }
            try {
                var response = await api(state.importEndpoint, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        csrf_token: state.csrfToken,
                        mode: 'manual_create',
                        filename: 'inserimento_manuale',
                        row: row
                    })
                });
                if (!Number(response.saved_rows || 0)) {
                    throw new Error('Il record non è stato salvato: verifica i campi catastali minimi obbligatori.');
                }
                allowClose = true;
                modal.hide();
                if (state.map) {
                    state.map.closePopup();
                }
                state.pendingNewMarkerFocus = {
                    lat: parseFiniteCoordinate(row.Latitudine),
                    lng: parseFiniteCoordinate(row.Longitudine),
                    provincia: row.Provincia || '',
                    comune: row.Comune || '',
                    cod_catastale: row['Codice Catastale'] || '',
                    sezione: row.Sezione || '',
                    foglio: row.Foglio || '',
                    particella: row.Particella || '',
                    subalterno: row.Subalterno || '',
                };
                await loadProperties();
                showManualRecordSavedModal(row);
            } catch (error) {
                if (feedback) {
                    feedback.className = 'alert alert-danger py-2';
                    feedback.textContent = error.message;
                }
            } finally {
                saveBtn.disabled = false;
            }
        });
    }

    async function saveProperty(button) {
        var wrapper    = button.closest('[data-property-id]') || button.closest('tr');
        var propertyId = Number(button.dataset.propertyId || (wrapper && wrapper.dataset.propertyId) || 0);
        if (!propertyId) return;
        var property        = findPropertyById(propertyId);
        var stateSelect     = wrapper && wrapper.querySelector('.state-select');
        var colorInput      = wrapper && wrapper.querySelector('.color-input');
        var noteInput       = wrapper && wrapper.querySelector('.note-input');
        var customStateInput = wrapper && wrapper.querySelector('.custom-state-input');
        var assignmentSelect = wrapper && wrapper.querySelector('.assignment-select');
        var assignments = assignmentSelect ? Array.from(assignmentSelect.selectedOptions).map(function (o) { return Number(o.value); }) : undefined;
        button.disabled = true;
        try {
            await savePropertyPayload({
                property_id: propertyId,
                stato: (stateSelect && stateSelect.value) || (property && property.stato),
                stato_personalizzato: (customStateInput ? customStateInput.value : (property && property.stato_personalizzato || '')),
                colore_marker: (colorInput && colorInput.value) || (property && property.colore_marker),
                note: (noteInput && noteInput.value) || '',
                assignments: assignments,
            });
        } catch (error) {
            alert(error.message);
        } finally {
            button.disabled = false;
        }
    }

    function parseCsvRows(text, delimiter) {
        var rows = [];
        var row = [];
        var value = '';
        var inQuotes = false;
        var i = 0;
        var len = text.length;

        while (i < len) {
            var ch = text[i];
            if (inQuotes) {
                if (ch === '"') {
                    if (i + 1 < len && text[i + 1] === '"') {
                        value += '"';
                        i += 2;
                        continue;
                    }
                    inQuotes = false;
                } else {
                    value += ch;
                }
                i++;
                continue;
            }

            if (ch === '"') {
                inQuotes = true;
                i++;
                continue;
            }
            if (ch === delimiter) {
                row.push(value);
                value = '';
                i++;
                continue;
            }
            if (ch === '\n' || ch === '\r') {
                row.push(value);
                value = '';
                rows.push(row);
                row = [];
                if (ch === '\r' && i + 1 < len && text[i + 1] === '\n') i++;
                i++;
                continue;
            }

            value += ch;
            i++;
        }

        row.push(value);
        rows.push(row);
        return rows;
    }

    function detectCsvDelimiter(text) {
        var candidates = ['\t', ';', ','];
        var best = { delimiter: ',', columns: 0, mismatches: Number.MAX_SAFE_INTEGER };
        for (var ci = 0; ci < candidates.length; ci++) {
            var candidate = candidates[ci];
            var parsed = parseCsvRows(text, candidate);
            var firstNonEmptyIndex = -1;
            for (var pi = 0; pi < parsed.length; pi++) {
                if (parsed[pi].some(function (cell) { return String(cell || '').trim() !== ''; })) {
                    firstNonEmptyIndex = pi;
                    break;
                }
            }
            if (firstNonEmptyIndex < 0) continue;
            var headerLen = parsed[firstNonEmptyIndex].length;
            if (headerLen <= 1) continue;
            var mismatches = 0;
            for (var ri = firstNonEmptyIndex + 1; ri < parsed.length; ri++) {
                var row = parsed[ri];
                var hasContent = row.some(function (cell) { return String(cell || '').trim() !== ''; });
                if (!hasContent) continue;
                if (row.length !== headerLen) mismatches++;
            }
            if (headerLen > best.columns || (headerLen === best.columns && mismatches < best.mismatches)) {
                best = { delimiter: candidate, columns: headerLen, mismatches: mismatches };
            }
        }
        return best.delimiter;
    }

    function parseCsvFile(text, fileName) {
        var parsedRows = [];
        var warnings = [];
        var delimiter = detectCsvDelimiter(text);
        var rows = parseCsvRows(text, delimiter);
        if (!rows.length) return { rows: parsedRows, warnings: warnings };
        var headerRowIndex = -1;
        for (var i = 0; i < rows.length; i++) {
            if (rows[i].some(function (cell) { return String(cell || '').trim() !== ''; })) {
                headerRowIndex = i;
                break;
            }
        }
        if (headerRowIndex < 0) return { rows: parsedRows, warnings: warnings };

        var headerCounts = {};
        var headers = rows[headerRowIndex].map(function (v, index) {
            var header = String(v || '').trim();
            if (!header) header = 'ColonnaVuota ' + (index + 1);
            if (headerCounts[header]) {
                headerCounts[header]++;
                return header + ' DUP ' + headerCounts[header];
            }
            headerCounts[header] = 1;
            return header;
        });

        for (var ri = headerRowIndex + 1; ri < rows.length; ri++) {
            var current = rows[ri];
            var hasContent = current.some(function (cell) { return String(cell || '').trim() !== ''; });
            if (!hasContent) continue;
            if (current.length !== headers.length) {
                warnings.push('File "' + fileName + '", riga ' + (ri + 1) + ': colonne disallineate (attese ' + headers.length + ', trovate ' + current.length + '). Riga ignorata.');
                continue;
            }
            var rowPayload = {};
            headers.forEach(function (header, ci) { rowPayload[header] = current[ci] !== undefined ? String(current[ci]).trim() : ''; });
            parsedRows.push(rowPayload);
        }

        return { rows: parsedRows, warnings: warnings };
    }

    async function parseFiles(files) {
        var parsedRows = [];
        var warnings = [];
        for (var fi = 0; fi < files.length; fi++) {
            var file   = files[fi];
            var buffer = await file.arrayBuffer();
            var fileName = String(file.name || '');
            var isCsv = fileName.toLowerCase().endsWith('.csv');
            if (isCsv) {
                var csvText = new TextDecoder('utf-8').decode(buffer);
                var csvResult = parseCsvFile(csvText, fileName);
                parsedRows = parsedRows.concat(csvResult.rows);
                warnings = warnings.concat(csvResult.warnings);
                continue;
            }
            var workbook = XLSX.read(buffer, { type: 'array', raw: false, dateNF: 'yyyy-mm-dd' });
            var sheet = workbook.Sheets[workbook.SheetNames[0]];
            var rows  = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: '', blankrows: false, raw: false });
            if (!rows.length) continue;
            var headerCounts = {};
            var headers = rows[0].map(function (v, index) {
                var header = String(v || '').trim();
                if (!header) header = 'ColonnaVuota ' + (index + 1);
                if (headerCounts[header]) {
                    headerCounts[header]++;
                    return header + ' DUP ' + headerCounts[header];
                }
                headerCounts[header] = 1;
                return header;
            });
            for (var ri = 1; ri < rows.length; ri++) {
                var current    = rows[ri];
                var rowPayload = {};
                headers.forEach(function (header, ci) { rowPayload[header] = current[ci] !== undefined ? String(current[ci]).trim() : ''; });
                parsedRows.push(rowPayload);
            }
        }
        return { rows: parsedRows, warnings: warnings };
    }

    function importLoggerReset() {
        var container = document.getElementById('enrichment-status-container');
        var phase = document.getElementById('import-phase');
        var bar   = document.getElementById('import-progress-bar');
        var text  = document.getElementById('import-progress-text');
        var log   = document.getElementById('import-log-console');
        var reportEl = document.getElementById('enrichment-report');
        if (container) container.style.display = '';
        if (phase)  phase.textContent  = 'Lettura file';
        if (bar)    bar.style.width    = '0%';
        if (text)   text.textContent   = 'Preparazione import...';
        if (log)    log.textContent    = '';
        if (reportEl) { reportEl.className = 'small d-none'; reportEl.innerHTML = ''; }
        state.currentImportStats = null;
        state.importUiFinalized = false;
        state.importLogSnapshots = { attempt_failures: {}, failure_codes: {} };
        resetImportUiClasses();
    }

    function importLog(level, message) {
        var log = document.getElementById('import-log-console');
        if (!log) return;
        var ts = new Date().toLocaleTimeString('it-IT', { hour12: false });
        log.textContent += '[' + ts + '] ' + String(level || 'info').toUpperCase().padEnd(7) + ' ' + message + '\n';
        log.scrollTop = log.scrollHeight;
    }

    function setImportPhase(phaseLabel, percent, statusText) {
        var phase = document.getElementById('import-phase');
        var bar   = document.getElementById('import-progress-bar');
        var text  = document.getElementById('import-progress-text');
        if (phase) phase.textContent = phaseLabel;
        if (bar && Number.isFinite(percent)) bar.style.width = clampPercent(percent) + '%';
        if (text && statusText) text.textContent = statusText;
    }

    function resetImportUiClasses() {
        var phase = document.getElementById('import-phase');
        var bar   = document.getElementById('import-progress-bar');
        var text  = document.getElementById('import-progress-text');
        if (phase) {
            phase.classList.remove('bg-success', 'bg-warning', 'bg-danger', 'bg-secondary');
            phase.classList.add('bg-primary');
        }
        if (bar) {
            bar.classList.remove('bg-success', 'bg-warning', 'bg-danger');
            bar.classList.add('bg-primary', 'progress-bar-striped', 'progress-bar-animated');
        }
        if (text) {
            text.classList.remove('text-danger', 'text-warning');
            text.classList.add('text-muted');
        }
    }

    function setWeightedImportPhase(phaseKey, ratio, statusText) {
        var phaseConfig = IMPORT_PHASE_WEIGHTS[phaseKey];
        if (!phaseConfig) return;
        var bounded = Math.max(0, Math.min(1, Number(ratio) || 0));
        var percent = phaseConfig.start + ((phaseConfig.end - phaseConfig.start) * bounded);
        setImportPhase(phaseConfig.label, percent, statusText);
    }

    function summarizeReconciliation(payload) {
        payload = payload || {};
        var total = Number(payload.total_rows || payload.totalRows || (state.currentImportStats ? state.currentImportStats.totalRows : 0) || 0);
        var geolocated = Number(payload.geolocated_rows || payload.geolocatedRows || 0);
        var missing = Number(payload.missing_rows || payload.missingRows || Math.max(0, total - geolocated));
        return {
            total: total,
            geolocated: geolocated,
            missing: missing,
            text: 'Completato: ' + geolocated + '/' + total + ' immobili geolocalizzati, ' + missing + ' senza coordinate'
        };
    }

    function finalizeImportUi(kind, message) {
        var container = document.getElementById('enrichment-status-container');
        var phase = document.getElementById('import-phase');
        var bar   = document.getElementById('import-progress-bar');
        var text  = document.getElementById('import-progress-text');
        var barClass = kind === 'success' ? 'bg-success' : (kind === 'warning' ? 'bg-warning' : 'bg-danger');
        var phaseLabel = kind === 'success' ? 'Completato' : (kind === 'warning' ? 'Completato con avvisi' : 'Errore');
        if (container) container.style.display = '';
        if (phase) {
            phase.textContent = phaseLabel;
            phase.classList.remove('bg-primary', 'bg-success', 'bg-warning', 'bg-danger', 'bg-secondary');
            phase.classList.add(barClass);
        }
        if (bar) {
            bar.classList.remove('bg-primary', 'bg-success', 'bg-warning', 'bg-danger', 'progress-bar-striped', 'progress-bar-animated');
            bar.classList.add(barClass);
            bar.style.width = '100%';
        }
        if (text) {
            text.textContent = message || phaseLabel;
            text.classList.remove('text-muted', 'text-danger', 'text-warning');
            text.classList.add(kind === 'danger' ? 'text-danger' : (kind === 'warning' ? 'text-warning' : 'text-muted'));
        }
        state.importUiFinalized = true;
    }

    function updateEnrichmentPhase(payload) {
        payload = payload || {};
        var processed = Number(payload.processed || 0);
        var total = Number(payload.total || 0);
        var ratio = total > 0 ? (processed / total) : (payload.done ? 1 : 0);
        var importPrefix = state.currentImportStats
            ? importProgressLabel(state.currentImportStats.savedRows, state.currentImportStats.totalRows) + ' · '
            : '';
        setWeightedImportPhase('enrich', ratio, importPrefix + 'Geolocalizzazione: ' + processed + '/' + total + ' (' + clampPercent(Math.round(ratio * 100)) + '%)');
    }

    function syncEnrichmentReportLog(report) {
        report = report || {};
        ['attempt_failures', 'failure_codes'].forEach(function (bucket) {
            var current = report[bucket] || {};
            var previous = state.importLogSnapshots[bucket] || {};
            Object.keys(current).forEach(function (code) {
                var nextValue = Number(current[code] || 0);
                var prevValue = Number(previous[code] || 0);
                if (nextValue <= prevValue) return;
                var delta = nextValue - prevValue;
                importLog(bucket === 'attempt_failures' ? 'debug' : 'warning', (bucket === 'attempt_failures' ? 'Tentativi provider falliti' : 'Particelle non risolte') + ' [' + code + ']: +' + delta);
            });
            state.importLogSnapshots[bucket] = Object.assign({}, current);
        });
    }

    async function runImport(files) {
        importLoggerReset();
        importLog('info', 'Fase Lettura file avviata');
        try {
            var parseResult = await parseFiles(files);
            var rows = parseResult.rows || [];
            (parseResult.warnings || []).forEach(function (warningMessage) {
                importLog('warning', warningMessage);
            });
            if (!rows.length) {
                importLog('warning', 'Nessuna riga valida trovata.');
                finalizeImportUi('warning', 'Nessuna riga valida trovata.');
                return;
            }

            setWeightedImportPhase('read', 1, importProgressLabel(0, rows.length) + ' · Righe lette: ' + rows.length);
            importLog('info', 'Righe lette: ' + rows.length);
            setWeightedImportPhase('analyze', 0.5, importProgressLabel(0, rows.length) + ' · Analisi duplicati in corso...');
            var analysis = await api(state.importEndpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ csrf_token: state.csrfToken, mode: 'analyze', rows: rows }) });
            importLog('info', 'Duplicati rilevati: ' + (analysis.conflicts || []).length);
            var decisions = {};
            for (var ci = 0; ci < (analysis.conflicts || []).length; ci++) {
                var conflict = analysis.conflicts[ci];
                var confirmUpdate = window.confirm('Duplicato per ' + conflict.comune + ' F.' + conflict.foglio + ' P.' + conflict.particella + (conflict.subalterno ? '/' + conflict.subalterno : '') + '.\nNuovo intestatario: ' + (conflict.incoming_owner || conflict.new_owner || 'N/D') + '.\nSostituire?');
                decisions[conflict.row_index] = confirmUpdate ? 'updated' : 'kept_old';
            }

            setWeightedImportPhase('save', 0.5, importProgressLabel(0, rows.length) + ' · Salvataggio dati in corso...');
            importLog('info', 'Fase Salvataggio dati avviata');
            var processPayload = await api(state.importEndpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ csrf_token: state.csrfToken, mode: 'process', filename: Array.from(files).map(function(f){return f.name;}).join(', '), decisions: decisions, rows: rows }) });
            state.currentImportStats = {
                savedRows: Number(processPayload.saved_rows || 0),
                totalRows: Number(processPayload.total_rows || rows.length)
            };
            updateEnrichmentPhase({
                processed: processPayload.processed_parcels || 0,
                total: processPayload.total_unique_parcels || 0,
                done: !!processPayload.enrichment_done
            });
            importLog('info', 'Righe salvate: ' + (processPayload.saved_rows !== undefined ? processPayload.saved_rows : rows.length));
            if (processPayload.skipped_rows) {
                importLog('warning', 'Righe saltate: ' + processPayload.skipped_rows);
            }
            Object.keys(processPayload.skipped_reasons || {}).forEach(function (reasonKey) {
                var reasonCount = Number(processPayload.skipped_reasons[reasonKey] || 0);
                if (!reasonCount) return;
                var reasonLabel = reasonKey === 'missing_cadastral_fields'
                    ? 'mancano i campi catastali minimi'
                    : reasonKey;
                importLog('warning', reasonCount + ' righe saltate: ' + reasonLabel);
            });
            if (processPayload.notes_imported) {
                importLog('info', 'Note importate: ' + processPayload.notes_imported);
            }
            importLog('info', 'Particelle geolocalizzate nel giro corrente: ' + (processPayload.geolocated_parcels || 0));
            renderEnrichmentReport({
                coord_source: processPayload.coord_source || {},
                attempt_failures: processPayload.attempt_failures || {},
                failure_codes: processPayload.failure_codes || {},
                unresolved_rows: processPayload.unresolved_rows || [],
                truncated: !!processPayload.unresolved_truncated
            });
            await loadProperties();
            if (!processPayload.batch_id) {
                importLog('error', 'Import completato senza batch_id di enrichment.');
                finalizeImportUi('danger', 'Errore: batch di geolocalizzazione non disponibile.');
                return;
            }
            if (processPayload.enrichment_done) {
                var summary = summarizeReconciliation(processPayload);
                finalizeImportUi(summary.missing > 0 ? 'warning' : 'success', summary.text);
                importLog(summary.missing > 0 ? 'warning' : 'info', summary.text);
                return;
            }
            importLog('info', 'Particelle residue eleggibili: ' + (processPayload.remaining_unique_parcels || 0));
            await enrichChunkLoop(processPayload.batch_id);
        } catch (error) {
            importLog('error', 'Import fallito: ' + (error && error.message ? error.message : 'Errore sconosciuto'));
            finalizeImportUi('danger', 'Errore durante l\'import: ' + (error && error.message ? error.message : 'Errore sconosciuto'));
            throw error;
        } finally {
            if (!state.importUiFinalized) {
                finalizeImportUi('danger', 'Import interrotto senza uno stato finale valido.');
            }
        }
    }

    async function pollEnrichment(batchId) {
        var reportEl = document.getElementById('enrichment-report');
        if (reportEl) { reportEl.className = 'small mt-2 d-none'; reportEl.innerHTML = ''; }
        try {
            var maxIterations = 240, iterations = 0, lastProcessed = -1, stalledSince = 0;
            while (iterations < maxIterations) {
                iterations++;
                var batch;
                try {
                    var p2 = await api(withTenant(state.importProgressEndpoint + '?batch_id=' + batchId));
                    batch = p2.batch;
                } catch (progressError) {
                    importLog('error', 'Polling enrichment non riuscito: ' + (progressError.message || 'errore sconosciuto'));
                    await new Promise(function(r){setTimeout(r,3000);});
                    continue;
                }
                var status = batch.enrichment_status || null;
                var processed = batch.enrichment_processed || 0;
                var total = batch.enrichment_total || 0;
                renderEnrichmentReport(batch.enrichment_report);
                updateEnrichmentPhase({ processed: processed, total: total, done: status === 'completed' });
                if (status === 'completed') {
                    await loadProperties();
                    var summary = summarizeReconciliation(batch);
                    finalizeImportUi(summary.missing > 0 ? 'warning' : 'success', summary.text);
                    importLog(summary.missing > 0 ? 'warning' : 'info', summary.text);
                    return;
                }
                if (status === 'failed') {
                    importLog('error', 'Geolocalizzazione batch fallita.');
                    finalizeImportUi('danger', 'Errore durante la geolocalizzazione del batch.');
                    return;
                }
                if (status === 'pending' && processed === 0) {
                    stalledSince++;
                    if (stalledSince >= 6 && state.enrichChunkEndpoint) {
                        importLog('warning', 'Polling fermo: avvio fallback sincrono.');
                        await enrichChunkLoop(batchId);
                        return;
                    }
                } else if (processed !== lastProcessed) {
                    stalledSince = 0;
                    lastProcessed = processed;
                }
                await new Promise(function(r){setTimeout(r,2500);});
            }
            importLog('error', 'Timeout polling enrichment.');
            finalizeImportUi('danger', 'Timeout geolocalizzazione. Usa "Rigenera coordinate mancanti".');
        } catch (error) {
            importLog('error', 'Errore polling enrichment: ' + (error && error.message ? error.message : 'Errore sconosciuto'));
            finalizeImportUi('danger', 'Errore polling geolocalizzazione: ' + (error && error.message ? error.message : 'Errore sconosciuto'));
            throw error;
        } finally {
            if (!state.importUiFinalized) {
                finalizeImportUi('danger', 'Geolocalizzazione interrotta senza stato finale.');
            }
        }
    }

    async function enrichChunkLoop(batchId) {
        var reportEl = document.getElementById('enrichment-report');
        if (reportEl) { reportEl.className = 'small mt-2 d-none'; reportEl.innerHTML = ''; }
        try {
            var maxChunks = 500, calls = 0;
            updateEnrichmentPhase({ processed: 0, total: 0, done: false });
            importLog('info', 'Avvio fallback chunk sincrono');
            while (calls < maxChunks) {
                calls++;
                var result;
                try {
                    result = await api(withTenant(state.enrichChunkEndpoint + '?batch_id=' + batchId + '&limit=25'), { allowErrorPayload: true });
                } catch (chunkError) {
                    importLog('error', 'Errore chiamata chunk: ' + (chunkError.message || 'errore sconosciuto'));
                    await new Promise(function(r){setTimeout(r,2000);});
                    continue;
                }
                if (result.ok === false) {
                    var errCode = result.error_code || 'unknown';
                    if (errCode === 'transient') {
                        importLog('info', 'Errore transient [' + errCode + '], nuovo tentativo.');
                        await new Promise(function(r){setTimeout(r,2000);});
                        continue;
                    }
                    importLog('error', 'Errore [' + errCode + ']: ' + (result.error || 'Errore sconosciuto'));
                    finalizeImportUi('danger', 'Errore [' + errCode + ']: ' + (result.error || 'Errore sconosciuto'));
                    return;
                }
                renderEnrichmentReport(result.enrichment_report);
                updateEnrichmentPhase(result);
                importLog('info', 'Chunk ' + calls + ': ' + (result.processed || 0) + '/' + (result.total || 0));
                if (result.done || result.status === 'completed') {
                    await loadProperties();
                    var summary = summarizeReconciliation(result);
                    finalizeImportUi(summary.missing > 0 ? 'warning' : 'success', summary.text);
                    importLog(summary.missing > 0 ? 'warning' : 'info', summary.text);
                    return;
                }
                if (result.status === 'failed') {
                    importLog('error', 'Geolocalizzazione chunk fallita.');
                    finalizeImportUi('danger', 'Errore durante la geolocalizzazione chunk.');
                    return;
                }
                await new Promise(function(r){setTimeout(r,200);});
            }
            importLog('error', 'Limite massimo chunk raggiunto.');
            finalizeImportUi('danger', 'Limite chunk raggiunto. Usa "Rigenera coordinate mancanti".');
        } catch (error) {
            importLog('error', 'Errore ciclo chunk: ' + (error && error.message ? error.message : 'Errore sconosciuto'));
            finalizeImportUi('danger', 'Errore geolocalizzazione sincrona: ' + (error && error.message ? error.message : 'Errore sconosciuto'));
            throw error;
        } finally {
            if (!state.importUiFinalized) {
                finalizeImportUi('danger', 'Geolocalizzazione sincrona interrotta senza stato finale.');
            }
        }
    }

    function renderEnrichmentReport(report) {
        var el = document.getElementById('enrichment-report');
        if (!el || !report || typeof report !== 'object') { if (el) { el.className = 'small mt-2 d-none'; el.innerHTML = ''; } return; }
        var sourceEntries  = Object.keys(report.coord_source  || {}).filter(function(k){return Number(report.coord_source[k])>0;});
        var attemptEntries = Object.keys(report.attempt_failures || {}).filter(function(k){return Number(report.attempt_failures[k])>0;});
        var failureEntries = Object.keys(report.failure_codes || {}).filter(function(k){return Number(report.failure_codes[k])>0;});
        var unresolved     = Array.isArray(report.unresolved_rows) ? report.unresolved_rows : [];
        if (!sourceEntries.length && !attemptEntries.length && !failureEntries.length && !unresolved.length) { el.className = 'small mt-2 d-none'; el.innerHTML = ''; return; }
        syncEnrichmentReportLog(report);
        var html = [];
        if (sourceEntries.length)  html.push('<div><strong>Sorgenti:</strong> '   + sourceEntries.map(function(k){return escapeHtml(k)+'='+escapeHtml(String(report.coord_source[k]));}).join(' &middot; ')  + '</div>');
        if (attemptEntries.length) html.push('<div class="mt-1 text-muted"><strong>Tentativi provider:</strong> ' + attemptEntries.map(function(k){return escapeHtml(k)+'='+escapeHtml(String(report.attempt_failures[k]));}).join(' &middot; ') + '</div>');
        if (failureEntries.length) html.push('<div class="mt-1"><strong>Irrecuperabili:</strong> ' + failureEntries.map(function(k){return escapeHtml(k)+'='+escapeHtml(String(report.failure_codes[k]));}).join(' &middot; ') + '</div>');
        if (unresolved.length)     html.push('<ul class="mb-0 mt-2 ps-3">' + unresolved.map(function(i){return '<li>'+escapeHtml(String(i))+'</li>';}).join('') + (report.truncated ? '<li>&hellip;</li>' : '') + '</ul>');
        el.className = 'small';
        el.innerHTML = html.join('');
    }

    function ensureSharedModals() {
        if (!document.getElementById('property-editor-modal')) {
            var editorModal = document.createElement('div');
            editorModal.innerHTML = '<div class="modal fade" id="property-editor-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Modifica marker</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button></div><div class="modal-body"><div id="property-editor-meta" class="small text-muted mb-3"></div><div id="property-editor-error" class="alert alert-danger py-2 px-3 small d-none mb-3"></div><div id="editor-owners-block" class="mb-3"><label id="editor-owners-label" class="form-label small mb-1">Intestatari e telefoni</label><div id="editor-owners-content"></div></div><div class="row g-2"><div class="col-md-6"><label class="form-label small mb-1">Stato</label><select id="editor-state" class="form-select form-select-sm"></select></div><div class="col-md-6"><label class="form-label small mb-1">Colore marker</label><div class="d-flex align-items-center gap-2"><span id="editor-color-preview" class="color-dot" style="width:18px;height:18px;"></span><select id="editor-color" class="form-select form-select-sm"></select></div></div><div class="col-12"><label class="form-label small mb-1">Stato personalizzato</label><input id="editor-custom-state" class="form-control form-control-sm" placeholder="Stato personalizzato"></div><div class="col-12"><label class="form-label small mb-1">Assegnazioni</label><div id="editor-assignments-summary" class="small"></div></div><div class="col-12"><button type="button" class="btn btn-outline-secondary btn-sm d-none" id="editor-assignments-open"><i class="bi bi-person-plus me-1"></i>Gestisci assegnazioni</button></div><div class="col-12"><label class="form-label small mb-1">Note (log)</label><div id="editor-note-log" class="border rounded p-2 bg-light-subtle small mb-2" style="max-height:170px;overflow:auto;"></div><input id="editor-note" class="form-control form-control-sm" placeholder="Scrivi una nota (una riga)"></div></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Annulla</button><button type="button" class="btn btn-primary btn-sm" id="editor-save-btn">Salva</button></div></div></div></div>';
            document.body.appendChild(editorModal.firstElementChild);
        }
        if (!document.getElementById('assignment-picker-modal')) {
            var assignmentModal = document.createElement('div');
            assignmentModal.innerHTML = '<div class="modal fade" id="assignment-picker-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Assegna subutenti</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button></div><div class="modal-body"><div id="assignment-picker-meta" class="small text-muted mb-2"></div><div id="assignment-picker-error" class="alert alert-danger py-2 px-3 small d-none mb-2"></div><div id="assignment-picker-list" class="vstack gap-2"></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Annulla</button><button type="button" class="btn btn-primary btn-sm" id="assignment-picker-save">Salva assegnazioni</button></div></div></div></div>';
            document.body.appendChild(assignmentModal.firstElementChild);
        }
        if (!document.getElementById('property-detail-modal')) {
            var detailModal = document.createElement('div');
            detailModal.innerHTML = '<div class="modal fade" id="property-detail-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Dettaglio marker</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button></div><div class="modal-body"><div id="property-detail-content" class="property-detail-wrapper"></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Chiudi</button></div></div></div></div>';
            document.body.appendChild(detailModal.firstElementChild);
        }
        if (!document.getElementById('owner-phone-confirm-modal')) {
            var phoneConfirmModal = document.createElement('div');
            phoneConfirmModal.innerHTML = '<div class="modal fade" id="owner-phone-confirm-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Conferma eliminazione</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button></div><div class="modal-body"><p id="owner-phone-confirm-message" class="mb-0 small"></p></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Annulla</button><button type="button" class="btn btn-danger btn-sm" id="owner-phone-confirm-delete">Elimina</button></div></div></div></div>';
            document.body.appendChild(phoneConfirmModal.firstElementChild);
        }
        if (!document.getElementById('manual-record-saved-modal')) {
            var savedModal = document.createElement('div');
            savedModal.innerHTML = '<div class="modal fade" id="manual-record-saved-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-dialog-centered"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Record salvato</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button></div><div class="modal-body"><div id="manual-record-saved-body"></div></div><div class="modal-footer"><button type="button" class="btn btn-primary btn-sm" data-bs-dismiss="modal">Chiudi</button></div></div></div></div>';
            document.body.appendChild(savedModal.firstElementChild);
        }
    }

    function setModalError(containerId, message) {
        var errorEl = document.getElementById(containerId);
        if (!errorEl) return;
        if (!message) { errorEl.classList.add('d-none'); errorEl.textContent = ''; return; }
        errorEl.textContent = message;
        errorEl.classList.remove('d-none');
    }

    function refreshEditorAssignmentSummary(property) {
        var summary = document.getElementById('editor-assignments-summary');
        if (!summary) return;
        summary.innerHTML = buildAssignmentSummary(property);
    }

    function buildEditorOwnersHtml(property) {
        var owners = property.owners || [];
        var canViewPhone = propertyCanViewPhone(property);
        if (!owners.length) {
            return '<div class="text-muted small">Nessun intestatario disponibile.</div>';
        }
        return owners.map(function (owner) {
            var fullName = ((owner.cognome || '') + ' ' + (owner.nome || '')).trim() || 'Intestatario';
            var identityParts = [];
            if (owner.codice_fiscale) identityParts.push('CF/P.IVA: ' + owner.codice_fiscale);
            if (owner.email) identityParts.push('\u2709 ' + owner.email);
            if (owner.indirizzo) identityParts.push('\uD83D\uDCCD ' + owner.indirizzo);
            var ownershipParts = [];
            var quota = ownerQuotaLabel(owner, property);
            var titolarita = ownerTitolaritaLabel(owner, property);
            if (quota) ownershipParts.push(quota);
            if (titolarita) ownershipParts.push(titolarita);
            return '<div class="border rounded p-2 mb-2">'
                + '<div class="fw-semibold small">' + escapeHtml(fullName) + '</div>'
                + (ownershipParts.length ? '<div class="owner-meta small">' + escapeHtml(ownershipParts.join(' · ')) + '</div>' : '')
                + (identityParts.length ? '<div class="owner-meta small">' + escapeHtml(identityParts.join(' | ')) + '</div>' : '')
                + (canViewPhone
                    ? '<div class="mt-1"><span class="small text-muted">Telefoni</span>'
                        + buildEditablePhoneChips(owner.telefono, property.id, owner.id || 0, !!property.can_edit && canViewPhone && Number(owner.id || 0) > 0)
                        + '</div>'
                    : '')
                + '</div>';
        }).join('');
    }

    function renderEditorOwners(property) {
        var container = document.getElementById('editor-owners-content');
        var label = document.getElementById('editor-owners-label');
        if (!container) return;
        container.innerHTML = buildEditorOwnersHtml(property);
        if (label) {
            label.textContent = propertyCanViewPhone(property) ? 'Intestatari e telefoni' : 'Intestatari';
        }
    }

    function renderEditorNotesLog(property) {
        var container = document.getElementById('editor-note-log');
        if (!container) return;
        container.innerHTML = propertyNotesHtml(property);
    }

    function toggleOwnerPhoneAddControls(container, showInput) {
        if (!container) return;
        var toggleBtn = container.querySelector('.add-owner-phone-toggle-btn');
        var input = container.querySelector('.add-owner-phone-input');
        var saveBtn = container.querySelector('.add-owner-phone-save-btn');
        var cancelBtn = container.querySelector('.add-owner-phone-cancel-btn');
        if (!toggleBtn || !input || !saveBtn || !cancelBtn) return;
        if (showInput) {
            toggleBtn.classList.add('d-none');
            input.classList.remove('d-none');
            saveBtn.classList.remove('d-none');
            cancelBtn.classList.remove('d-none');
            input.focus();
            return;
        }
        input.value = '';
        input.classList.add('d-none');
        saveBtn.classList.add('d-none');
        cancelBtn.classList.add('d-none');
        toggleBtn.classList.remove('d-none');
    }

    function showMapFeedback(message, type, timeout) {
        var feedback = document.getElementById('map-cadastral-feedback');
        if (!feedback) return;
        if (!message) {
            feedback.className = 'alert alert-light border shadow-sm d-none analyticspro-map-feedback';
            feedback.textContent = '';
            return;
        }
        feedback.className = 'alert alert-' + (type || 'light') + ' shadow-sm analyticspro-map-feedback';
        feedback.textContent = message;
        if (timeout) {
            window.setTimeout(function () {
                if (feedback.textContent === message) {
                    showMapFeedback('', 'light');
                }
            }, timeout);
        }
    }

    function ensureLeafletEpsg4258() {
        if (!window.L || L.CRS.EPSG4258) return;
        L.CRS.EPSG4258 = L.extend({}, L.CRS.EPSG4326, {
            code: 'EPSG:4258',
            projection: L.Projection.LonLat,
            transformation: new L.Transformation(1 / 180, 1, -1 / 180, 0.5),
            wrapLng: [-180, 180],
            wrapLat: [-90, 90],
        });
    }

    function proxifyCadastralUrl(url) {
        if (!state.wmsProxyEndpoint) {
            return url;
        }
        var separator = state.wmsProxyEndpoint.indexOf('?') === -1 ? '?' : '&';
        return state.wmsProxyEndpoint + separator + 'url=' + encodeURIComponent(url);
    }

    function cadastralLayerBaseUrl(useDirectUrl) {
        return useDirectUrl ? CATASTRAL_WMS_BASE_URL : proxifyCadastralUrl(CATASTRAL_WMS_BASE_URL);
    }

    function deproxifyCadastralTileUrl(url) {
        var raw = String(url || '').trim();
        if (!raw || !state.wmsProxyEndpoint) return raw;
        try {
            var parsed = new URL(raw, window.location.origin);
            var upstreamRaw = parsed.searchParams.get('url');
            if (!upstreamRaw) return raw;
            var upstream = new URL(upstreamRaw, window.location.origin);
            parsed.searchParams.forEach(function (value, key) {
                if (key === 'url' || key.indexOf('_ap_') === 0) return;
                upstream.searchParams.append(key, value);
            });
            return upstream.toString();
        } catch (error) {
            return raw;
        }
    }

    function createFindAreaPinIcon() {
        return L.divIcon({
            className: '',
            html: '<span class="analyticspro-find-area-pin" aria-hidden="true"></span>',
            iconSize: [28, 28],
            iconAnchor: [14, 28],
            popupAnchor: [0, -26],
        });
    }

    function buildFindAreaPopupHtml(payload) {
        var details = [];
        if (payload.comune) details.push('<li><strong>Comune:</strong> ' + escapeHtml(payload.comune) + '</li>');
        if (payload.foglio) details.push('<li><strong>Foglio:</strong> ' + escapeHtml(payload.foglio) + '</li>');
        if (payload.particella) details.push('<li><strong>Particella:</strong> ' + escapeHtml(payload.particella) + '</li>');
        return '<div class="map-popup-wrapper">'
            + '<div class="card map-popup-card">'
            + '<div class="card-header py-2 px-3 fw-semibold"><i class="bi bi-geo-alt-fill me-1"></i>Risultato Trova area</div>'
            + '<div class="card-body py-2 px-3"><ul class="list-unstyled small mb-0">' + details.join('') + '</ul></div>'
            + '</div></div>';
    }

    function syncCadastralOpacityControl() {
        var wrap = document.getElementById('cadastral-opacity-control');
        var slider = document.getElementById('cadastral-opacity-slider');
        var valueEl = document.getElementById('cadastral-opacity-value');
        if (!slider || !valueEl) return;
        var initialOpacity = getStoredCadastralOpacity();
        var initialValue = Math.round(initialOpacity * 100);
        slider.value = String(initialValue);
        valueEl.textContent = initialValue + '%';
        if (state.cadastralLayer) {
            state.cadastralLayer.setOpacity(initialOpacity);
        }
        if (wrap) {
            wrap.classList.toggle('d-none', !state.cadastralLayerEnabled);
        }
        slider.disabled = !state.cadastralLayerEnabled;
        valueEl.classList.toggle('text-muted', !state.cadastralLayerEnabled);
    }

    function buildCadastralPopupHtml(details, latlng) {
        var rows = [];
        [['Provincia', details.provincia], ['Comune', details.comune], ['Codice catastale', details.cod_catastale], ['Foglio', details.foglio], ['Particella', details.particella], ['Subalterno', details.subalterno], ['Categoria', details.categoria], ['Indirizzo', details.indirizzo]].forEach(function (entry) {
            if (!entry[1]) return;
            rows.push('<li><strong>' + escapeHtml(entry[0]) + ':</strong> ' + escapeHtml(entry[1]) + '</li>');
        });
        return '<div class="map-popup-wrapper analyticspro-cadastral-popup">'
            + '<div class="card map-popup-card">'
            + '<div class="card-header py-2 px-3 fw-semibold"><i class="bi bi-map me-1"></i>Dati catastali</div>'
            + '<div class="card-body py-2 px-3">'
            + (rows.length
                ? '<ul class="list-unstyled small mb-3">' + rows.join('') + '</ul>'
                : '<div class="small text-muted mb-3">Nessun dato catastale disponibile.</div>')
            + (!hasCadastralParcelDetails(details) && hasCadastralLocationDetails(details)
                ? '<div class="small text-muted mb-3"><i class="bi bi-geo-alt me-1"></i>Comune e provincia sono stati rilevati dalla coordinata; la particella non è disponibile in questo punto.</div>'
                : '')
            + (state.canImport
                ? '<div class="d-flex justify-content-end"><button type="button" class="btn btn-primary btn-sm add-cadastral-marker-btn"'
                    + ' data-lat="' + escapeHtml(String(latlng.lat)) + '"'
                    + ' data-lng="' + escapeHtml(String(latlng.lng)) + '"'
                    + ' data-comune="' + escapeHtml(details.comune || '') + '"'
                    + ' data-provincia="' + escapeHtml(details.provincia || '') + '"'
                    + ' data-cod-catastale="' + escapeHtml(details.cod_catastale || '') + '"'
                    + ' data-sezione="' + escapeHtml(details.sezione || '') + '"'
                    + ' data-foglio="' + escapeHtml(details.foglio || '') + '"'
                    + ' data-particella="' + escapeHtml(details.particella || '') + '"'
                    + ' data-subalterno="' + escapeHtml(details.subalterno || '') + '"'
                    + ' data-categoria="' + escapeHtml(details.categoria || '') + '"'
                    + ' data-indirizzo="' + escapeHtml(details.indirizzo || '') + '"'
                    + ' data-civico="' + escapeHtml(details.civico || '') + '">'
                    + '<i class="bi bi-plus-circle me-1"></i>Crea nuovo marker</button></div>'
                : '')
            + '</div></div></div>';
    }

    function cadastralTileRetryUrl(url, attempt) {
        var retryParam = '_ap_tile_retry';
        var retryValue = String(attempt || 0);
        try {
            var parsed = new URL(url, window.location.origin);
            parsed.searchParams.set(retryParam, retryValue);
            return parsed.toString();
        } catch (error) {
            var separator = url.indexOf('?') === -1 ? '?' : '&';
            return url + separator + retryParam + '=' + encodeURIComponent(retryValue);
        }
    }

    function clearCadastralTileWarning() {
        state.cadastralTileWarningShown = false;
        var feedback = document.getElementById('map-cadastral-feedback');
        if (feedback && /^Layer catastale non disponibile/.test(feedback.textContent || '')) {
            showMapFeedback('', 'light');
        }
    }

    function showCadastralTileWarning(status) {
    //    if (state.cadastralTileWarningShown) return;
    //    state.cadastralTileWarningShown = true;
    //    showMapFeedback('Layer catastale non disponibile' + (status || '') + '.', 'warning', 3200);
    }

    function handleCadastralTileLoad(event) {
        if (!state.cadastralLayerEnabled) return;
        var tile = event && event.tile ? event.tile : null;
        if (tile && ((tile.currentSrc || tile.src || '') === CATASTRAL_ERROR_TILE_URL)) return;
        if (tile && tile.dataset) {
            tile.dataset.apRetryCount = '0';
            tile.dataset.apFallbackUsed = '0';
        }
        clearCadastralTileWarning();
    }

    function handleCadastralTileError(event) {
        var tile = event && event.tile ? event.tile : null;
        var status = event && event.error && event.error.status ? ' (' + event.error.status + ')' : '';
        if (!tile || !tile.dataset) {
            showCadastralTileWarning(status);
            return;
        }
        if (!tile.dataset.apOriginalSrc) {
            tile.dataset.apOriginalSrc = tile.currentSrc || tile.src || '';
        }
        if (!tile.dataset.apDirectSrc) {
            tile.dataset.apDirectSrc = deproxifyCadastralTileUrl(tile.dataset.apOriginalSrc);
        }
        var attempts = parseInt(tile.dataset.apRetryCount || '0', 10);
        if (!Number.isFinite(attempts)) attempts = 0;
        var fallbackUrl = tile.dataset.apDirectSrc || tile.dataset.apOriginalSrc;
        if (tile.dataset.apFallbackUsed !== '1' && fallbackUrl && fallbackUrl !== tile.dataset.apOriginalSrc) {
            tile.dataset.apRetryCount = String(attempts + 1);
            tile.dataset.apFallbackUsed = '1';
            window.setTimeout(function () {
                if (!state.cadastralLayerEnabled) return;
                tile.src = cadastralTileRetryUrl(fallbackUrl, attempts + 1);
            }, CATASTRAL_TILE_RETRY_DELAY_MS * (attempts + 1));
            return;
        }
        if (attempts < CATASTRAL_TILE_RETRY_LIMIT && fallbackUrl) {
            tile.dataset.apRetryCount = String(attempts + 1);
            window.setTimeout(function () {
                if (!state.cadastralLayerEnabled) return;
                tile.src = cadastralTileRetryUrl(fallbackUrl, attempts + 1);
            }, CATASTRAL_TILE_RETRY_DELAY_MS * (attempts + 1));
            return;
        }
        tile.src = CATASTRAL_ERROR_TILE_URL;
        showCadastralTileWarning(status);
    }

    function createLeafletEpsg4258TileUrl(tileLayer, coords) {
        var bounds = tileLayer._tileCoordsToNwSe(coords);
        var crs = tileLayer.options.crs || (tileLayer._map && tileLayer._map.options ? tileLayer._map.options.crs : null) || L.CRS.EPSG3857;
        var projectedNorthWest = crs.project(bounds[0]);
        var projectedSouthEast = crs.project(bounds[1]);
        var bbox = [
            projectedSouthEast.y,
            projectedNorthWest.x,
            projectedNorthWest.y,
            projectedSouthEast.x,
        ].join(',');
        return tileLayer._url
            + L.Util.getParamString(tileLayer.wmsParams, tileLayer._url, tileLayer.options.uppercase)
            + (tileLayer.options.uppercase ? '&BBOX=' : '&bbox=')
            + bbox;
    }

    function hasCadastralLocationDetails(details) {
        return !!(details && (details.comune || details.provincia || details.cod_catastale || details.foglio || details.particella));
    }

    function hasCadastralParcelDetails(details) {
        return !!(details && (details.foglio || details.particella || details.sezione || details.subalterno));
    }

    function createCadastralLayer() {
        if (!state.map) return null;
        ensureLeafletEpsg4258();
        var layer = L.tileLayer.wms(cadastralLayerBaseUrl(true), {
            layers: 'province,CP.CadastralZoning,CP.CadastralParcel,fabbricati,strade,vestizioni,acque',
            styles: '',
            format: 'image/png',
            transparent: true,
            version: '1.3.0',
            crs: L.CRS.EPSG4258,
            opacity: getStoredCadastralOpacity(),
            zIndex: 200,
            minZoom: CATASTRAL_MIN_ZOOM,
            maxZoom: 22,
            attribution: '© Agenzia delle Entrate',
            uppercase: true,
            updateWhenIdle: false,
            updateWhenZooming: false,
            keepBuffer: 4,
            errorTileUrl: CATASTRAL_ERROR_TILE_URL,
        });
        layer.getTileUrl = function (coords) {
            return createLeafletEpsg4258TileUrl(this, coords);
        };
        layer.on('add', function () {
            clearCadastralTileWarning();
            layer.setOpacity(getStoredCadastralOpacity());
            syncCadastralOpacityControl();
        });
        layer.on('tileload', handleCadastralTileLoad);
        layer.on('tileerror', handleCadastralTileError);
        return layer;
    }

    function syncCadastralClickBinding() {
        if (!state.map) return;
        if (state.cadastralLayerEnabled && !state.cadastralClickBound) {
            state.map.on('click', handleCadastralMapClick);
            state.cadastralClickBound = true;
            return;
        }
        if (!state.cadastralLayerEnabled && state.cadastralClickBound) {
            state.map.off('click', handleCadastralMapClick);
            state.cadastralClickBound = false;
        }
    }

    function setCadastralLayerEnabled(enabled) {
        state.cadastralLayerEnabled = !!enabled;
        var toggle = document.getElementById('cadastral-layer-toggle');
        if (toggle) toggle.checked = state.cadastralLayerEnabled;
        writeLocalFlag('cadastral-layer-enabled', state.cadastralLayerEnabled);
        if (state.cadastralLayerEnabled) {
            if (!state.cadastralLayer) {
                state.cadastralLayer = createCadastralLayer();
            }
            if (state.cadastralLayer && state.map && !state.map.hasLayer(state.cadastralLayer)) {
                state.cadastralLayer.addTo(state.map);
            }
            if (state.cadastralLayer) {
                state.cadastralLayer.setOpacity(getStoredCadastralOpacity());
            }
        } else if (state.cadastralLayer && state.map && state.map.hasLayer(state.cadastralLayer)) {
            state.map.removeLayer(state.cadastralLayer);
            clearCadastralTileWarning();
        }
        syncCadastralClickBinding();
        syncCadastralOpacityControl();
    }

    function extractCadastralDetailsFromAjax(payload) {
        if (Array.isArray(payload)) {
            payload = payload.find(function (entry) { return entry && typeof entry === 'object'; }) || null;
        }
        if (payload && typeof payload === 'object') {
            if (payload.data && typeof payload.data === 'object') {
                payload = payload.data;
            } else if (payload.result && typeof payload.result === 'object') {
                payload = payload.result;
            } else if (payload.feature && typeof payload.feature === 'object') {
                payload = payload.feature;
            }
        }
        if (!payload || typeof payload !== 'object') return null;
        var comune = payload.COMUNE || payload.comune || payload.DESCR_COMUNE || '';
        var foglio = payload.FOGLIO || payload.foglio || '';
        var particella = payload.NUM_PART || payload.particella || payload.PARTICELLA || '';
        var subalterno = payload.SUBALTERNO || payload.subalterno || payload.NUM_SUB || '';
        var categoria = payload.CATEGORIA || payload.categoria || '';
        var indirizzo = payload.INDIRIZZO || payload.indirizzo || '';
        if (!comune && !foglio && !particella) {
            return null;
        }
        return {
            comune: comune,
            foglio: foglio,
            particella: particella,
            subalterno: subalterno,
            categoria: categoria,
            indirizzo: indirizzo,
            cod_catastale: payload.COD_COMUNE || payload.cod_catastale || '',
            sezione: payload.SEZIONE || payload.sezione || '',
            provincia: payload.PROVINCIA || payload.provincia || '',
            civico: payload.CIVICO || payload.civico || '',
        };
    }

    function buildDirectCadastralLookupUrl(latlng) {
        return CATASTRAL_ADE_AJAX_URL
            + '&lon=' + encodeURIComponent(String(latlng.lng))
            + '&lat=' + encodeURIComponent(String(latlng.lat));
    }

    function appendCadastralResolveFields(query, fields) {
        if (!fields || typeof fields !== 'object') {
            return query;
        }
        [['provincia', fields.provincia], ['comune', fields.comune], ['cod_catastale', fields.cod_catastale], ['sezione', fields.sezione], ['foglio', fields.foglio], ['particella', fields.particella], ['subalterno', fields.subalterno], ['categoria', fields.categoria], ['indirizzo', fields.indirizzo], ['civico', fields.civico]].forEach(function (entry) {
            if (String(entry[1] || '').trim() === '') return;
            query += '&' + encodeURIComponent(entry[0]) + '=' + encodeURIComponent(String(entry[1]));
        });
        return query;
    }

    async function tryDirectCadastralLookup(latlng) {
        var response;
        try {
            response = await fetch(buildDirectCadastralLookupUrl(latlng), {
                method: 'GET',
                mode: 'cors',
                signal: window.AbortSignal && typeof window.AbortSignal.timeout === 'function'
                    ? window.AbortSignal.timeout(CATASTRAL_LOOKUP_TIMEOUT_MS)
                    : undefined,
            });
        } catch (error) {
            if (error && (error.name === 'AbortError' || error.name === 'TimeoutError' || /timed out|timeout/i.test(String(error.message || '')))) {
                throw new Error('Timeout nel recupero dei dati catastali AdE. Riprova tra poco.');
            }
            throw new Error('Impossibile contattare il servizio catastale AdE dal browser. Riprova tra poco.');
        }
        var rawBody = '';
        var payload = null;
        try {
            rawBody = String(await response.text() || '').replace(/^\uFEFF/, '').trim();
            payload = rawBody ? JSON.parse(rawBody) : null;
        } catch (error) {
            throw new Error('Risposta non valida dal servizio catastale. Riprova tra poco.');
        }
        if (!response.ok) {
            throw new Error('Il servizio catastale AdE non ha risposto correttamente.');
        }
        return extractCadastralDetailsFromAjax(payload);
    }

    async function requestCadastralFeatureInfo(latlng, options) {
        if (!state.featureInfoEndpoint) {
            throw new Error('Endpoint catastale non configurato.');
        }
        options = options || {};
        var resolveLocation = !!options.resolveLocation;
        var timeoutMs = resolveLocation ? CATASTRAL_RESOLVE_LOCATION_TIMEOUT_MS : CATASTRAL_LOOKUP_TIMEOUT_MS;
        var zoom = state.map ? state.map.getZoom() : '';
        var query = '?lat=' + encodeURIComponent(String(latlng.lat))
            + '&lng=' + encodeURIComponent(String(latlng.lng))
            + '&zoom=' + encodeURIComponent(String(zoom));
        if (resolveLocation) {
            query += '&resolve_location=1';
            query = appendCadastralResolveFields(query, options.fields || null);
        }
        var response;
        try {
            response = await fetch(state.featureInfoEndpoint + query, {
                method: 'GET',
                headers: { 'Accept': 'application/json' },
                signal: window.AbortSignal && typeof window.AbortSignal.timeout === 'function'
                    ? window.AbortSignal.timeout(timeoutMs)
                    : undefined,
            });
        } catch (error) {
            if (error && (error.name === 'AbortError' || error.name === 'TimeoutError' || /timed out|timeout/i.test(String(error.message || '')))) {
                throw new Error(resolveLocation
                    ? 'Ricerca comune e provincia scaduta. Apri il marker e compila i campi manualmente.'
                    : 'Timeout nel recupero dei dati catastali AdE. Riprova tra poco.');
            }
            throw error;
        }
        var payload = null;
        var rawBody = '';
        try {
            rawBody = await response.text();
            payload = rawBody ? JSON.parse(rawBody) : null;
        } catch (error) {
            throw new Error('Risposta non valida dal servizio catastale. Riprova tra poco.');
        }
        if (!payload || typeof payload !== 'object') {
            throw new Error('Risposta non valida dal servizio catastale. Riprova tra poco.');
        }
        if (!response.ok || payload.ok === false) {
            if (response.status === 404 || payload.found === false) {
                return null;
            }
            throw new Error(payload.error || 'Impossibile recuperare i dati catastali.');
        }
        if (payload.found === false) {
            if (payload.status === 'upstream_timeout' || payload.status === 'upstream_error') {
                throw new Error(payload.message || 'Il servizio catastale AdE non ha risposto correttamente.');
            }
            return null;
        }
        return payload;
    }

    async function handleCadastralMapClick(event) {
        if (!state.cadastralLayerEnabled || !state.map) return;
        if (state.map.getZoom() < CATASTRAL_MIN_ZOOM) {
            showMapFeedback('Ingrandisci la mappa almeno al livello 10 per interrogare il layer catastale.', 'warning', 2600);
            return;
        }
        showMapFeedback('Recupero dati catastali in corso…', 'info');
        var details = null;
        try {
            details = await tryDirectCadastralLookup(event.latlng);
        } catch (error) {
            showMapFeedback(error.message || 'Impossibile recuperare i dati catastali.', 'danger', 3200);
            return;
        }
        if (!hasCadastralLocationDetails(details)) {
            showMapFeedback('Nessuna particella in questo punto.', 'warning', 2600);
            details = details && typeof details === 'object' ? details : {};
        } else {
            showMapFeedback(
                hasCadastralParcelDetails(details)
                    ? 'Dati catastali trovati.'
                    : 'Comune e provincia rilevati; particella non disponibile in questo punto.',
                hasCadastralParcelDetails(details) ? 'success' : 'warning',
                2200
            );
        }
        state.cadastralPopup = L.popup({ maxWidth: 420 })
            .setLatLng(event.latlng)
            .setContent(buildCadastralPopupHtml(details, event.latlng))
            .openOn(state.map);
    }

    function normalizeComuneKey(value) {
        var normalized = String(value || '').trim().toUpperCase();
        if (normalized.normalize) {
            normalized = normalized.normalize('NFD').replace(/[\u0300-\u036f]/g, '');
        }
        return normalized.replace(/\s+/g, ' ');
    }

    async function findAreaComuni(query) {
        if (!state.findAreaComuniEndpoint) return [];
        var normalized = String(query || '').trim();
        if (normalized.length < 3) return { comuni: [] };
        return api(state.findAreaComuniEndpoint + '?q=' + encodeURIComponent(normalized));
    }

    function hideFindAreaAutocomplete() {
        var list = document.getElementById('find-area-comune-results');
        if (!list) return;
        list.classList.add('d-none');
        list.innerHTML = '';
        state.findAreaAutocompleteItems = [];
    }

    function renderFindAreaAutocomplete(rows) {
        var list = document.getElementById('find-area-comune-results');
        if (!list) return;
        state.findAreaAutocompleteItems = rows.slice();
        if (!rows.length) {
            hideFindAreaAutocomplete();
            return;
        }
        list.innerHTML = rows.map(function (row, index) {
            var comune = String(row.comune || '').trim();
            var province = String(row.provincia || '').trim();
            var label = province ? (comune + ' (' + province + ')') : comune;
            return '<button type="button" class="list-group-item list-group-item-action find-area-comune-option"'
                + ' data-index="' + index + '"'
                + ' data-comune="' + escapeHtml(comune) + '"'
                + ' data-belfiore="' + escapeHtml(row.belfiore || '') + '">'
                + escapeHtml(label)
                + '</button>';
        }).join('');
        list.classList.remove('d-none');
    }

    function selectFindAreaComune(row) {
        var comuneInput = document.getElementById('find-area-comune');
        if (!comuneInput || !row) return;
        comuneInput.value = row.comune || '';
        comuneInput.dataset.belfiore = row.belfiore || '';
        hideFindAreaAutocomplete();
    }

    function showFindAreaOnMap(payload) {
        if (!state.map) return;
        if (state.findAreaMarker && state.map.hasLayer(state.findAreaMarker)) {
            state.map.removeLayer(state.findAreaMarker);
        }
        if (state.findAreaBoundsLayer && state.map.hasLayer(state.findAreaBoundsLayer)) {
            state.map.removeLayer(state.findAreaBoundsLayer);
        }

        var bounds = payload && payload.bounds ? payload.bounds : null;
        if (bounds && Number.isFinite(Number(bounds.min_lat)) && Number.isFinite(Number(bounds.min_lng)) && Number.isFinite(Number(bounds.max_lat)) && Number.isFinite(Number(bounds.max_lng))) {
            state.findAreaBoundsLayer = L.rectangle([[Number(bounds.min_lat), Number(bounds.min_lng)], [Number(bounds.max_lat), Number(bounds.max_lng)]], {
                color: '#0d6efd',
                weight: 2,
                fillOpacity: 0.05,
            }).addTo(state.map);
            state.map.fitBounds(state.findAreaBoundsLayer.getBounds().pad(0.2));
        } else if (Number.isFinite(Number(payload.lat)) && Number.isFinite(Number(payload.lng))) {
            var zoom = Number(payload.zoom || 17);
            state.map.setView([Number(payload.lat), Number(payload.lng)], Number.isFinite(zoom) ? zoom : 17);
        }

        if (Number.isFinite(Number(payload.lat)) && Number.isFinite(Number(payload.lng))) {
            state.findAreaMarker = L.marker([Number(payload.lat), Number(payload.lng)], {
                icon: createFindAreaPinIcon(),
                keyboard: false,
            }).addTo(state.map);
            state.findAreaMarker.bindPopup(buildFindAreaPopupHtml(payload), { maxWidth: 320 }).openPopup();
        }
    }

    function initFindArea() {
        var comuneInput = document.getElementById('find-area-comune');
        var foglioInput = document.getElementById('find-area-foglio');
        var particellaInput = document.getElementById('find-area-particella');
        var feedback = document.getElementById('find-area-feedback');
        var button = document.getElementById('find-area-submit');
        var list = document.getElementById('find-area-comune-results');
        if (!comuneInput || !foglioInput || !button || !feedback || !state.findAreaEndpoint) return;

        var comuneTimer = null;
        comuneInput.addEventListener('input', function () {
            var query = comuneInput.value.trim();
            comuneInput.dataset.belfiore = '';
            if (comuneTimer) window.clearTimeout(comuneTimer);
            if (query.length < 3 || !state.findAreaComuniEndpoint || !list) {
                hideFindAreaAutocomplete();
                return;
            }
            comuneTimer = window.setTimeout(function () {
                findAreaComuni(query)
                    .then(function (payload) {
                        state.findAreaComuneMap = {};
                        var rows = [];
                        (payload.comuni || []).forEach(function (row) {
                            var key = normalizeComuneKey(row.comune || '');
                            if (key) state.findAreaComuneMap[key] = row.belfiore || '';
                            rows.push(row);
                        });
                        renderFindAreaAutocomplete(rows);
                    })
                    .catch(function (error) {
                        hideFindAreaAutocomplete();
                        if (window.console && typeof window.console.warn === 'function') {
                            window.console.warn('[analyticspro] Autocomplete comuni non disponibile', error);
                        }
                    });
            }, 300);
        });

        comuneInput.addEventListener('change', function () {
            comuneInput.dataset.belfiore = state.findAreaComuneMap[normalizeComuneKey(comuneInput.value)] || '';
        });
        comuneInput.addEventListener('blur', function () {
            window.setTimeout(hideFindAreaAutocomplete, 120);
        });
        comuneInput.addEventListener('focus', function () {
            if (state.findAreaAutocompleteItems.length) {
                renderFindAreaAutocomplete(state.findAreaAutocompleteItems);
            }
        });

        button.addEventListener('click', function () {
            if (!state.map) return;
            var comune = comuneInput.value.trim();
            var foglio = foglioInput.value.trim();
            var particella = particellaInput ? particellaInput.value.trim() : '';
            if (!comuneInput.dataset.belfiore) {
                comuneInput.dataset.belfiore = state.findAreaComuneMap[normalizeComuneKey(comune)] || '';
            }
            if (!comune || !foglio) {
                feedback.className = 'small text-danger mt-1';
                feedback.textContent = 'Inserisci comune e foglio.';
                return;
            }
            button.disabled = true;
            feedback.className = 'small text-muted mt-1';
            feedback.textContent = 'Ricerca in corso…';

            var qs = '?comune=' + encodeURIComponent(comune)
                + '&foglio=' + encodeURIComponent(foglio)
                + '&particella=' + encodeURIComponent(particella)
                + '&belfiore=' + encodeURIComponent(comuneInput.dataset.belfiore || '');
            api(state.findAreaEndpoint + qs)
                .then(function (payload) {
                    showFindAreaOnMap(payload);
                    feedback.className = 'small text-success mt-1';
                    feedback.textContent = payload.message || 'Area trovata.';
                })
                .catch(function (error) {
                    feedback.className = 'small text-danger mt-1';
                    feedback.textContent = error.message || 'Area non trovata.';
                })
                .finally(function () { button.disabled = false; });
        });
        [comuneInput, foglioInput, particellaInput].forEach(function (field) {
            if (!field) return;
            field.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter') return;
                event.preventDefault();
                button.click();
            });
        });
        if (list) {
            list.addEventListener('click', function (event) {
                var option = event.target.closest('.find-area-comune-option');
                if (!option) return;
                var row = state.findAreaAutocompleteItems[Number(option.dataset.index || -1)] || {
                    comune: option.dataset.comune || '',
                    belfiore: option.dataset.belfiore || '',
                };
                selectFindAreaComune(row);
            });
        }
    }

    function initCadastralUi() {
        var toggle = document.getElementById('cadastral-layer-toggle');
        var slider = document.getElementById('cadastral-opacity-slider');
        var valueEl = document.getElementById('cadastral-opacity-value');
        if (!toggle) return;
        toggle.checked = state.cadastralLayerEnabled;
        toggle.addEventListener('change', function () {
            setCadastralLayerEnabled(toggle.checked);
            if (toggle.checked) {
                showMapFeedback('Layer catastale attivato. Clicca la mappa per leggere i dati catastali del punto selezionato.', 'info', 2600);
            } else {
                showMapFeedback('', 'light');
                if (state.map) state.map.closePopup();
            }
        });
        if (slider && valueEl) {
            slider.addEventListener('input', function () {
                var nextValue = parseInt(slider.value, 10);
                var opacity = Number.isFinite(nextValue) ? Math.max(15, Math.min(100, nextValue)) / 100 : DEFAULT_CATASTRAL_OPACITY;
                valueEl.textContent = Math.round(opacity * 100) + '%';
                if (state.cadastralLayer) {
                    state.cadastralLayer.setOpacity(opacity);
                }
                setStoredCadastralOpacity(opacity);
            });
        }
        syncCadastralOpacityControl();
    }

    function confirmOwnerPhoneRemoval(phone) {
        ensureSharedModals();
        if (state.phoneConfirmPending) {
            return Promise.resolve(false);
        }
        var modalEl = document.getElementById('owner-phone-confirm-modal');
        var messageEl = document.getElementById('owner-phone-confirm-message');
        var confirmBtn = document.getElementById('owner-phone-confirm-delete');
        if (!modalEl || !messageEl || !confirmBtn) {
            return Promise.resolve(false);
        }

        state.phoneConfirmPending = true;
        messageEl.textContent = 'Eliminare il numero ' + phone + '? L\'operazione è irreversibile.';
        return new Promise(function (resolve) {
            var resolved = false;
            var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            var cleanup = function () {
                confirmBtn.removeEventListener('click', onConfirm);
                modalEl.removeEventListener('hidden.bs.modal', onHidden);
                state.phoneConfirmPending = false;
            };
            var onConfirm = function () {
                if (resolved) return;
                resolved = true;
                cleanup();
                modal.hide();
                resolve(true);
            };
            var onHidden = function () {
                if (resolved) return;
                resolved = true;
                cleanup();
                resolve(false);
            };
            confirmBtn.addEventListener('click', onConfirm);
            modalEl.addEventListener('hidden.bs.modal', onHidden);
            modal.show();
        });
    }

    function openEditorModal(propertyId) {
        ensureSharedModals();
        var property = findPropertyById(propertyId);
        if (!property) { alert('Immobile non trovato.'); return; }
        if (!property.can_edit) { alert('Non hai i permessi per modificare questo marker.'); return; }
        var modalEl = document.getElementById('property-editor-modal');
        var meta = document.getElementById('property-editor-meta');
        var stateEl = document.getElementById('editor-state');
        var colorEl = document.getElementById('editor-color');
        var customStateEl = document.getElementById('editor-custom-state');
        var noteEl = document.getElementById('editor-note');
        var saveBtn = document.getElementById('editor-save-btn');
        var assignmentBtn = document.getElementById('editor-assignments-open');
        if (!modalEl || !stateEl || !colorEl || !customStateEl || !noteEl || !saveBtn) return;
        setModalError('property-editor-error', '');
        meta.textContent = (property.comune || '') + ' \u00B7 ' + unitLabel(property) + ' \u00B7 ' + ((property.indirizzo || '') + ' ' + (property.civico || '')).trim();
        stateEl.innerHTML = buildSelectOptions(property.stato !== null && property.stato !== undefined ? property.stato : '');
        var allowedColors = MARKER_COLOR_PALETTE.map(function (item) { return item.value; });
        var defaultColor = defaultColorForState(property.stato || '');
        var isLegacyColor = allowedColors.indexOf(property.colore_marker || '') === -1 && !!property.colore_marker;
        var selectedColor = isLegacyColor ? property.colore_marker : (property.colore_marker || defaultColor);
        colorEl.innerHTML = colorPaletteOptions(selectedColor, isLegacyColor ? property.colore_marker : '');
        colorEl.value = selectedColor;
        colorEl.dataset.autoColor = (!isLegacyColor && selectedColor === defaultColor) ? '1' : '0';
        colorEl.dataset.originalColor = property.colore_marker || defaultColor;
        updateEditorColorPreview(selectedColor);
        updateColorSelectAppearance(colorEl);
        customStateEl.value = property.stato_personalizzato || '';
        noteEl.value = '';
        saveBtn.dataset.propertyId = String(property.id);
        renderEditorOwners(property);
        renderEditorNotesLog(property);
        refreshEditorAssignmentSummary(property);
        if (assignmentBtn) { assignmentBtn.classList.toggle('d-none', state.role === 'subuser'); assignmentBtn.dataset.propertyId = String(property.id); }
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    function openDetailModal(propertyId) {
        ensureSharedModals();
        var property = findPropertyById(propertyId);
        var modalEl = document.getElementById('property-detail-modal');
        var bodyEl  = document.getElementById('property-detail-content');
        if (!property || !modalEl || !bodyEl) return;
        modalEl.dataset.propertyId = String(property.id);
        bodyEl.innerHTML = buildPropertyCardHtml(property, { mapMode: false });
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    function openAssignmentPicker(propertyId) {
        ensureSharedModals();
        var property = findPropertyById(propertyId);
        var modalEl  = document.getElementById('assignment-picker-modal');
        var listEl   = document.getElementById('assignment-picker-list');
        var metaEl   = document.getElementById('assignment-picker-meta');
        var saveBtn  = document.getElementById('assignment-picker-save');
        if (!property || !modalEl || !listEl || !metaEl || !saveBtn) return;
        if (state.role === 'subuser') return;
        setModalError('assignment-picker-error', '');
        var selected = new Set((property.assignments || []).map(function (item) { return Number(item.subuser_id); }));
        metaEl.textContent = (property.comune || '') + ' \u00B7 ' + unitLabel(property);
        listEl.innerHTML = state.subusers.length
            ? state.subusers.map(function (subuser) {
                return '<label class="form-check"><input class="form-check-input assignment-picker-check" type="checkbox" value="' + subuser.id + '"' + (selected.has(Number(subuser.id)) ? ' checked' : '') + '><span class="form-check-label">' + escapeHtml(subuser.nome + ' ' + subuser.cognome) + '</span></label>';
            }).join('')
            : '<div class="text-muted small">Nessun subutente disponibile.</div>';
        saveBtn.dataset.propertyId = String(property.id);
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    var adeLogModal = (function () {
        var modalEl = document.getElementById('ade-log-modal');
        if (!modalEl) return null;
        var bsModal  = new bootstrap.Modal(modalEl);
        var bodyEl   = document.getElementById('ade-log-modal-body');
        var statusEl = document.getElementById('ade-log-modal-status');
        var footerEl = document.getElementById('ade-log-modal-footer');
        var titleEl  = document.getElementById('ade-log-modal-label');
        var currentJobId = null, lastLogId = 0, pollingTimer = null, userScrolled = false;
        var STATUS_COLORS = { queued: 'secondary', extracting: 'info', importing: 'primary', verifying: 'warning', completed: 'success', failed: 'danger' };
        var LOG_COLORS    = { error: 'text-danger', warning: 'text-warning', info: 'text-light' };
        function formatSize(bytes) { if (bytes >= 1048576) return (bytes/1048576).toFixed(1)+' MB'; if (bytes >= 1024) return (bytes/1024).toFixed(1)+' KB'; return bytes+' B'; }
        function appendLogs(logs) {
            if (!bodyEl || !logs.length) return;
            var wasAtBottom = bodyEl.scrollHeight - bodyEl.scrollTop <= bodyEl.clientHeight + 40;
            logs.forEach(function (log) {
                var div = document.createElement('div');
                div.className   = LOG_COLORS[log.level] || 'text-light';
                div.textContent = '[' + log.created_at + '] ' + (log.level || 'info').toUpperCase().padEnd(7) + ' ' + log.message;
                bodyEl.appendChild(div);
                if (log.id) lastLogId = Math.max(lastLogId, Number(log.id));
            });
            if (!userScrolled || wasAtBottom) bodyEl.scrollTop = bodyEl.scrollHeight;
        }
        function updateStatus(job) { if (!statusEl) return; var color = STATUS_COLORS[job.status] || 'secondary'; statusEl.innerHTML = '<span class="badge bg-' + escapeHtml(color) + ' me-2">' + escapeHtml(job.status) + '</span>' + formatAdeJobProgress(job); }
        async function poll() {
            if (!currentJobId) return;
            try {
                var payload = await api(state.adeJobsEndpoint + '?job_id=' + currentJobId + '&after_id=' + lastLogId);
                var job = payload.job;
                if (job) updateStatus(job);
                appendLogs(payload.logs || []);
                var done = job && (job.status === 'completed' || job.status === 'failed');
                if (done) { stopPolling(); if (footerEl) footerEl.textContent = job.status === 'completed' ? 'Job completato.' : 'Job fallito: ' + (job.error_message || 'errore sconosciuto'); }
                else pollingTimer = setTimeout(poll, 1500);
            } catch(e) { pollingTimer = setTimeout(poll, 3000); }
        }
        function stopPolling() { if (pollingTimer) { clearTimeout(pollingTimer); pollingTimer = null; } }
        if (bodyEl) bodyEl.addEventListener('scroll', function () { userScrolled = bodyEl.scrollHeight - bodyEl.scrollTop > bodyEl.clientHeight + 60; });
        modalEl.addEventListener('hidden.bs.modal', stopPolling);
        return {
            open: function (jobId, label) { currentJobId = jobId; lastLogId = 0; userScrolled = false; if (bodyEl) bodyEl.innerHTML = ''; if (titleEl) titleEl.textContent = label || 'Job #' + jobId; if (statusEl) statusEl.innerHTML = ''; if (footerEl) footerEl.textContent = 'Connessione\u2026'; bsModal.show(); poll(); },
            formatSize: formatSize,
        };
    })();

    function isAdeSqlJob(job) { return String((job && job.zip_filename) || '').toLowerCase().endsWith('.sql'); }
    function formatAdeJobProgress(job) {
        if (isAdeSqlJob(job)) return 'INSERT comuni ' + escapeHtml(String(job.processed_comuni)) + '/' + escapeHtml(String(job.total_comuni)) + ' &middot; INSERT particelle ' + escapeHtml(String(job.processed_particelle)) + '/' + escapeHtml(String(job.total_particelle));
        return 'Comuni ' + escapeHtml(String(job.processed_comuni)) + '/' + escapeHtml(String(job.total_comuni)) + ' &middot; Particelle ' + escapeHtml(String(job.processed_particelle)) + '/' + escapeHtml(String(job.total_particelle));
    }

    function setAdeUploadButtonState(inputId, buttonId, idleHtml, loadingHtml, isUploading, hasFilesOverride) {
        isUploading = isUploading === true;
        var input = document.getElementById(inputId), button = document.getElementById(buttonId);
        if (!input || !button) return;
        var hasFiles = typeof hasFilesOverride === 'boolean' ? hasFilesOverride : Boolean(input.files && input.files.length);
        button.disabled = isUploading || !hasFiles;
        button.innerHTML = isUploading ? loadingHtml : idleHtml;
    }

    async function submitAdeUpload(inputId, buttonId, importType, idleHtml, loadingHtml) {
        var input = document.getElementById(inputId);
        if (!input || !input.files || !input.files.length) return;
        var formData = new FormData();
        formData.append('csrf_token', state.csrfToken);
        formData.append('import_type', importType);
        Array.from(input.files).forEach(function (file) { formData.append('files[]', file); });
        setAdeUploadButtonState(inputId, buttonId, idleHtml, loadingHtml, true);
        try {
            var response = await fetch(withTenant(state.adeJobsEndpoint), { method: 'POST', body: formData });
            var payload = null; try { payload = await response.json(); } catch(e) {}
            if (!response.ok || !payload || !payload.ok) throw new Error((payload && payload.error) || 'Upload fallito (' + response.status + ')');
            input.value = '';
            setAdeUploadButtonState(inputId, buttonId, idleHtml, loadingHtml, false, false);
            var latestJobId = (payload.job_ids && payload.job_ids.length) ? payload.job_ids[payload.job_ids.length - 1] : null;
            await refreshAdeJobs();
            if (latestJobId && adeLogModal) adeLogModal.open(latestJobId, 'Job #' + latestJobId);
        } catch (error) { setAdeUploadButtonState(inputId, buttonId, idleHtml, loadingHtml, false); throw error; }
    }

    async function refreshAdeJobs() {
        var container = document.getElementById('ade-jobs');
        if (!container) return;
        var payload = await api(state.adeJobsEndpoint);
        var jobs = payload.jobs || [];
        container.innerHTML = jobs.map(function (job) {
            var percent = Number(job.total_particelle) > 0 ? Math.round(Number(job.processed_particelle) / Number(job.total_particelle) * 100) : 0;
            return '<div class="border rounded p-3 mb-2"><div class="d-flex justify-content-between flex-wrap gap-2"><strong>' + escapeHtml(job.provincia_sigla) + ' &middot; ' + escapeHtml(job.zip_filename) + '</strong><span class="badge text-bg-secondary">' + escapeHtml(job.status) + '</span></div><div class="progress my-2" style="height:6px;"><div class="progress-bar" style="width:' + percent + '%"></div></div><div class="small text-muted">' + formatAdeJobProgress(job) + '</div></div>';
        }).join('') || '<p class="text-muted mb-0">Nessun job ADE presente.</p>';
    }

    async function loadAdeServerFiles(options) {
        options = options || {};
        var type = options.type || 'zip', listId = options.listId || 'ade-server-files-list', selectAllId = options.selectAllId || 'ade-server-select-all', submitId = options.submitId || 'ade-server-submit';
        var emptyLabel = options.emptyLabel || 'Nessun file presente.';
        var listEl = document.getElementById(listId), selectAllBtn = document.getElementById(selectAllId), submitBtn = document.getElementById(submitId);
        if (!listEl) return;
        if (!state.adeManualFilesEndpoint) { listEl.innerHTML = '<p class="text-danger small mb-0">Endpoint non configurato.</p>'; if (selectAllBtn) selectAllBtn.style.display = 'none'; if (submitBtn) submitBtn.style.display = 'none'; return; }
        listEl.innerHTML = '<div class="text-muted small">Caricamento\u2026</div>';
        try {
            var payload = await api(state.adeManualFilesEndpoint + '?type=' + encodeURIComponent(type));
            var files = payload.files || [];
            if (!files.length) { listEl.innerHTML = '<p class="text-muted small mb-0">' + emptyLabel + '</p>'; if (selectAllBtn) selectAllBtn.style.display = 'none'; if (submitBtn) submitBtn.style.display = 'none'; return; }
            listEl.innerHTML = '<div class="list-group list-group-flush border rounded mb-2">' + files.map(function (f) { return '<label class="list-group-item list-group-item-action py-2 px-3 d-flex align-items-center gap-2"><input type="checkbox" class="form-check-input ade-server-file-check" value="' + escapeHtml(f.name) + '"><span class="flex-grow-1 text-truncate font-monospace small">' + escapeHtml(f.name) + '</span><span class="text-muted small text-nowrap">' + escapeHtml((adeLogModal && adeLogModal.formatSize(f.size)) || String(f.size)) + '</span></label>'; }).join('') + '</div>';
            if (selectAllBtn) selectAllBtn.style.removeProperty('display');
            if (submitBtn) { submitBtn.style.removeProperty('display'); submitBtn.disabled = true; }
            listEl.querySelectorAll('.ade-server-file-check').forEach(function (cb) { cb.addEventListener('change', function () { if (submitBtn) submitBtn.disabled = !listEl.querySelector('.ade-server-file-check:checked'); }); });
        } catch (error) { listEl.innerHTML = '<p class="text-danger small mb-0">Errore: ' + escapeHtml(error.message) + '</p>'; }
    }

    async function submitAdeServerFiles(options) {
        options = options || {};
        var type = options.type || 'zip', listId = options.listId || 'ade-server-files-list', submitId = options.submitId || 'ade-server-submit';
        var idleHtml = options.idleHtml || '<i class="bi bi-play-fill me-1"></i>Importa selezionati';
        var loadingHtml = options.loadingHtml || '<span class="spinner-border spinner-border-sm me-1"></span>Elaborazione\u2026';
        var reloadOptions = options.reloadOptions || options;
        var listEl = document.getElementById(listId), submitBtn = document.getElementById(submitId);
        if (!listEl || !state.adeManualFilesEndpoint) return;
        var checked = Array.from(listEl.querySelectorAll('.ade-server-file-check:checked')).map(function (cb) { return cb.value; });
        if (!checked.length) return;
        if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = loadingHtml; }
        try {
            var formData = new FormData(); formData.append('csrf_token', state.csrfToken); formData.append('type', type); checked.forEach(function (name) { formData.append('files[]', name); });
            var response = await fetch(state.adeManualFilesEndpoint, { method: 'POST', body: formData });
            var payload = null; try { payload = await response.json(); } catch(e) {}
            if (!response.ok || !payload || !payload.ok) throw new Error((payload && payload.error) || 'Errore (' + response.status + ')');
            await loadAdeServerFiles(reloadOptions); await refreshAdeJobs();
            var latestJobId = (payload.job_ids && payload.job_ids.length) ? payload.job_ids[payload.job_ids.length - 1] : null;
            if (latestJobId && adeLogModal) adeLogModal.open(latestJobId, 'Job #' + latestJobId);
        } catch (error) { alert(error.message); if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = idleHtml; } }
    }

    document.addEventListener('change', function (event) {
        if (event.target.id === 'import-files' && event.target.files && event.target.files.length) { runImport(event.target.files).catch(function (e) { if (state.overlay) state.overlay.hide(); alert(e.message); }); }
        if (event.target.classList.contains('state-select')) { var wr = event.target.closest('[data-property-id]') || event.target.closest('tr'); var ci = wr && wr.querySelector('.color-input'); if (ci) ci.value = defaultColorForState(event.target.value); }
        if (event.target.id === 'editor-state') {
            var ce2 = document.getElementById('editor-color');
            if (ce2 && ce2.dataset.autoColor !== '0') {
                ce2.value = defaultColorForState(event.target.value);
                ce2.dataset.autoColor = '1';
                updateEditorColorPreview(ce2.value);
                updateColorSelectAppearance(ce2);
            }
        }
        if (event.target.id === 'editor-color') {
            event.target.dataset.autoColor = '0';
            updateEditorColorPreview(event.target.value);
            updateColorSelectAppearance(event.target);
        }
        if (event.target.id === 'assigned-subuser-filter') { var sid = event.target.value; api(withTenant(state.propertiesEndpoint + '?mode=assigned' + (sid ? '&subuser_id=' + sid : ''))).then(function (p) { state.assignedProperties = p.properties || []; renderAssignedTable(); }).catch(function (e) { alert(e.message); }); }
        if (event.target.id === 'assigned-assignment-filter') renderAssignedTable();
        if (event.target.id === 'report-filter-color') {
            updateColorSelectAppearance(event.target);
            updateReportFilterColorPreview(event.target.value);
            applyReportFilters();
        }
        if (event.target.id === 'report-filter-stato') applyReportFilters();
        if (event.target.id === 'ade-zips')      setAdeUploadButtonState('ade-zips',      'ade-zips-submit',  '<i class="bi bi-cloud-upload me-1"></i>Importa',     '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Importazione\u2026', false);
        if (event.target.id === 'ade-sql-files') setAdeUploadButtonState('ade-sql-files', 'ade-sql-submit',   '<i class="bi bi-cloud-upload me-1"></i>Importa SQL', '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Importazione\u2026', false);
    });

    document.addEventListener('click', function (event) {
        var t = event.target;
        var phoneBtn = t.closest('.copy-phone-btn');
        if (phoneBtn) {
            event.preventDefault();
            copyTextToClipboard(phoneBtn.dataset.phone || '')
                .then(function () {
                    var originalLabel = phoneBtn.dataset.defaultLabel || phoneBtn.dataset.phone || '';
                    phoneBtn.textContent = 'Copiato!';
                    phoneBtn.classList.remove('btn-outline-primary');
                    phoneBtn.classList.add('btn-success');
                    window.setTimeout(function () {
                        phoneBtn.textContent = originalLabel;
                        phoneBtn.classList.add('btn-outline-primary');
                        phoneBtn.classList.remove('btn-success');
                    }, 1200);
                })
                .catch(function () { window.alert('Impossibile copiare il numero.'); });
            return;
        }
        var removePhoneBtn = t.closest('.remove-owner-phone-btn');
        if (removePhoneBtn) {
            event.preventDefault();
            var phone = String(removePhoneBtn.dataset.phone || '').trim();
            var propertyId = Number(removePhoneBtn.dataset.propertyId || 0);
            var ownerId = Number(removePhoneBtn.dataset.ownerId || 0);
            if (!phone || !propertyId || !ownerId) return;
            var propertyForRemoval = findPropertyById(propertyId);
            if (!propertyForRemoval || !propertyCanViewPhone(propertyForRemoval) || !propertyForRemoval.can_edit) return;
            confirmOwnerPhoneRemoval(phone).then(function (confirmed) {
                if (!confirmed) return;
                removePhoneBtn.disabled = true;
                removeOwnerPhone(propertyId, ownerId, phone)
                    .then(function (payload) {
                        applyOwnerPhoneUpdate(propertyId, ownerId, payload.telefono || '');
                        var property = findPropertyById(propertyId);
                        if (property) {
                            renderEditorOwners(property);
                        }
                    })
                    .catch(function (error) { window.alert(error.message); })
                    .finally(function () { removePhoneBtn.disabled = false; });
            });
            return;
        }
        var addPhoneToggleBtn = t.closest('.add-owner-phone-toggle-btn');
        if (addPhoneToggleBtn) {
            event.preventDefault();
            toggleOwnerPhoneAddControls(addPhoneToggleBtn.closest('.owner-phone-add-controls'), true);
            return;
        }
        var addPhoneCancelBtn = t.closest('.add-owner-phone-cancel-btn');
        if (addPhoneCancelBtn) {
            event.preventDefault();
            toggleOwnerPhoneAddControls(addPhoneCancelBtn.closest('.owner-phone-add-controls'), false);
            return;
        }
        var addPhoneSaveBtn = t.closest('.add-owner-phone-save-btn');
        if (addPhoneSaveBtn) {
            event.preventDefault();
            var addWrap = addPhoneSaveBtn.closest('.owner-phone-add-controls');
            if (!addWrap) return;
            var inputPhone = addWrap.querySelector('.add-owner-phone-input');
            var newPhone = String((inputPhone && inputPhone.value) || '').trim();
            var addPropertyId = Number(addWrap.dataset.propertyId || 0);
            var addOwnerId = Number(addWrap.dataset.ownerId || 0);
            if (!newPhone) {
                window.alert('Inserisci un numero di telefono.');
                return;
            }
            if (!/^[0-9+\-\s]+$/.test(newPhone)) {
                window.alert('Formato numero non valido. Usa solo cifre, spazi, + e -.');
                return;
            }
            var propertyForAdd = findPropertyById(addPropertyId);
            if (!propertyForAdd || !propertyCanViewPhone(propertyForAdd) || !propertyForAdd.can_edit || !addOwnerId) return;
            addPhoneSaveBtn.disabled = true;
            addOwnerPhone(addPropertyId, addOwnerId, newPhone)
                .then(function (payload) {
                    applyOwnerPhoneUpdate(addPropertyId, addOwnerId, payload.telefono || '');
                    var property = findPropertyById(addPropertyId);
                    if (property) renderEditorOwners(property);
                })
                .catch(function (error) { window.alert(error.message); })
                .finally(function () { addPhoneSaveBtn.disabled = false; });
            return;
        }
        var cadastralAddBtn = t.closest('.add-cadastral-marker-btn');
        if (cadastralAddBtn) {
            event.preventDefault();
            var autoFillValues = cadastralButtonValues(cadastralAddBtn);
            if (String(autoFillValues['Provincia'] || '').trim() !== '' && String(autoFillValues['Comune'] || '').trim() !== '') {
                openCadastralManualRecord(autoFillValues, true, '');
                return;
            }
            setCadastralAddButtonLoading(cadastralAddBtn, true);
            requestCadastralFeatureInfo({
                lat: Number(cadastralAddBtn.dataset.lat || 0),
                lng: Number(cadastralAddBtn.dataset.lng || 0),
            }, {
                resolveLocation: true,
                fields: {
                    provincia: cadastralAddBtn.dataset.provincia || '',
                    comune: cadastralAddBtn.dataset.comune || '',
                    cod_catastale: cadastralAddBtn.dataset.codCatastale || '',
                    sezione: cadastralAddBtn.dataset.sezione || '',
                    foglio: cadastralAddBtn.dataset.foglio || '',
                    particella: cadastralAddBtn.dataset.particella || '',
                    subalterno: cadastralAddBtn.dataset.subalterno || '',
                    categoria: cadastralAddBtn.dataset.categoria || '',
                    indirizzo: cadastralAddBtn.dataset.indirizzo || '',
                    civico: cadastralAddBtn.dataset.civico || '',
                },
            })
                .then(function (payload) {
                    var resolvedValues = mergeCadastralManualRecordValues(autoFillValues, payload || {});
                    var hasResolvedLocation = String(resolvedValues['Provincia'] || '').trim() !== '' && String(resolvedValues['Comune'] || '').trim() !== '';
                    openCadastralManualRecord(
                        resolvedValues,
                        hasResolvedLocation,
                        hasResolvedLocation ? '' : 'Comune/provincia non rilevati automaticamente, compilali manualmente.'
                    );
                })
                .catch(function (error) {
                    openCadastralManualRecord(
                        autoFillValues,
                        false,
                        error && error.message ? error.message : 'Comune/provincia non rilevati automaticamente, compilali manualmente.'
                    );
                })
                .finally(function () {
                    setCadastralAddButtonLoading(cadastralAddBtn, false);
                });
            return;
        }
        if (t.closest('.close-map-popup'))    { event.preventDefault(); if (state.map) state.map.closePopup(); return; }
        if (t.closest('.close-detail-modal')) { event.preventDefault(); var dm = bootstrap.Modal.getInstance(document.getElementById('property-detail-modal')); if (dm) dm.hide(); return; }
        var detailBtn = t.closest('.open-detail-modal');   if (detailBtn)     { event.preventDefault(); openDetailModal(Number(detailBtn.dataset.propertyId || 0)); return; }
        var editorBtn = t.closest('.open-editor-modal');   if (editorBtn)     { event.preventDefault(); openEditorModal(Number(editorBtn.dataset.propertyId || 0)); return; }
        var deleteBtn = t.closest('.delete-property-btn');
        if (deleteBtn) {
            event.preventDefault();
            var deleteId = Number(deleteBtn.dataset.propertyId || 0);
            if (!deleteId) return;
            if (!window.confirm('Confermi l\'eliminazione definitiva di questo immobile?')) return;
            deleteProperty(deleteId).catch(function (error) { window.alert(error.message); });
            return;
        }
        var assignBtn = t.closest('.assignment-picker-btn'); if (assignBtn)   { event.preventDefault(); openAssignmentPicker(Number(assignBtn.dataset.propertyId || 0)); return; }
        if (t.id === 'editor-assignments-open') { event.preventDefault(); openAssignmentPicker(Number(t.dataset.propertyId || 0)); return; }
        if (t.id === 'assignment-picker-save') {
            event.preventDefault();
            var pid = Number(t.dataset.propertyId || 0), prop = findPropertyById(pid); if (!prop) return;
            var checks = Array.from(document.querySelectorAll('#assignment-picker-list .assignment-picker-check:checked'));
            var assignments = checks.map(function (c) { return Number(c.value); }).filter(Number.isFinite);
            t.disabled = true;
            savePropertyPayload({ property_id: pid, stato: prop.stato, stato_personalizzato: prop.stato_personalizzato || '', colore_marker: prop.colore_marker, note: '', assignments: assignments })
                .then(function () { var m = bootstrap.Modal.getInstance(document.getElementById('assignment-picker-modal')); if (m) m.hide(); var upd = findPropertyById(pid); if (upd) refreshEditorAssignmentSummary(upd); })
                .catch(function (e) { setModalError('assignment-picker-error', e.message); })
                .finally(function () { t.disabled = false; });
            return;
        }
        if (t.id === 'editor-save-btn') {
            event.preventDefault();
            var pid2 = Number(t.dataset.propertyId || 0), prop2 = findPropertyById(pid2); if (!prop2) return;
            var se = document.getElementById('editor-state'), ce = document.getElementById('editor-color'), cse = document.getElementById('editor-custom-state'), ne = document.getElementById('editor-note');
            t.disabled = true; setModalError('property-editor-error', '');
            savePropertyPayload({ property_id: pid2, stato: (se && se.value) || prop2.stato, stato_personalizzato: (cse && cse.value) || '', colore_marker: (ce && ce.value) || prop2.colore_marker, note: (ne && ne.value) || '' })
                .then(function () { var m = bootstrap.Modal.getInstance(document.getElementById('property-editor-modal')); if (m) m.hide(); })
                .catch(function (e) { setModalError('property-editor-error', e.message); })
                .finally(function () { t.disabled = false; });
            return;
        }
        var saveButton = t.closest('.property-save'); if (saveButton) { event.preventDefault(); saveProperty(saveButton); }
        if (t.id === 'refresh-map') loadProperties().catch(function (e) { alert(e.message); });
        if (t.id === 'btn-apply-filter') {
            var cbs = document.querySelectorAll('.map-stato-filter:checked');
            var nf  = Array.from(cbs).map(function (cb) { return cb.value; });
            state.mapStatiFilter = nf.length > 0 ? nf : Object.keys(STATE_OPTIONS).slice();
            var categoryChecks = document.querySelectorAll('.map-categoria-filter:checked');
            state.mapCategoriaFilter = Array.from(categoryChecks).map(function (cb) { return cb.value; });
            renderMap();
        }
        if (t.id === 'btn-select-all-stati') { var cbs2 = document.querySelectorAll('.map-stato-filter'); var allC = Array.from(cbs2).every(function(cb){return cb.checked;}); cbs2.forEach(function(cb){cb.checked=!allC;}); }
        if (t.id === 'btn-select-all-categorie') {
            var cbs3 = document.querySelectorAll('.map-categoria-filter');
            var allC3 = Array.from(cbs3).every(function(cb){return cb.checked;});
            cbs3.forEach(function(cb){cb.checked=!allC3;});
            state.mapCategoriaFilter = Array.from(document.querySelectorAll('.map-categoria-filter:checked')).map(function (cb) { return cb.value; });
            renderMap();
        }
        if (t.id === 'ade-zips-submit')      submitAdeUpload('ade-zips',      'ade-zips-submit',  'zip', '<i class="bi bi-cloud-upload me-1"></i>Importa',     '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Importazione\u2026').catch(function(e){alert(e.message);});
        if (t.id === 'ade-sql-submit')       submitAdeUpload('ade-sql-files', 'ade-sql-submit',   'sql', '<i class="bi bi-cloud-upload me-1"></i>Importa SQL', '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Importazione\u2026').catch(function(e){alert(e.message);});
        if (t.id === 'ade-server-submit')     submitAdeServerFiles({ type:'zip', listId:'ade-server-files-list',     submitId:'ade-server-submit',     idleHtml:'<i class="bi bi-play-fill me-1"></i>Importa selezionati',     loadingHtml:'<span class="spinner-border spinner-border-sm me-1"></span>Elaborazione\u2026', reloadOptions:{type:'zip', listId:'ade-server-files-list',     selectAllId:'ade-server-select-all',     submitId:'ade-server-submit',     emptyLabel:'Nessun file ZIP presente in <code>storage/manual_upload/</code>.'} }).catch(function(e){alert(e.message);});
        if (t.id === 'ade-server-sql-submit') submitAdeServerFiles({ type:'sql', listId:'ade-server-sql-files-list', submitId:'ade-server-sql-submit', idleHtml:'<i class="bi bi-play-fill me-1"></i>Importa SQL selezionati', loadingHtml:'<span class="spinner-border spinner-border-sm me-1"></span>Elaborazione\u2026', reloadOptions:{type:'sql', listId:'ade-server-sql-files-list', selectAllId:'ade-server-sql-select-all', submitId:'ade-server-sql-submit', emptyLabel:'Nessun file SQL presente in <code>storage/manual_upload/</code>.'} }).catch(function(e){alert(e.message);});
        if (t.id === 'ade-server-select-all')     { var le1=document.getElementById('ade-server-files-list');     var sb1=document.getElementById('ade-server-submit');     var ac1=le1?le1.querySelectorAll('.ade-server-file-check'):[]; var allC1=Array.from(ac1).every(function(cb){return cb.checked;}); ac1.forEach(function(cb){cb.checked=!allC1;}); if(sb1)sb1.disabled=allC1; }
        if (t.id === 'ade-server-sql-select-all') { var le2=document.getElementById('ade-server-sql-files-list'); var sb2=document.getElementById('ade-server-sql-submit'); var ac2=le2?le2.querySelectorAll('.ade-server-file-check'):[]; var allC2=Array.from(ac2).every(function(cb){return cb.checked;}); ac2.forEach(function(cb){cb.checked=!allC2;}); if(sb2)sb2.disabled=allC2; }
        var logBtn = t.closest('.ade-open-log-btn'); if (logBtn && adeLogModal) { adeLogModal.open(Number(logBtn.dataset.jobId), logBtn.dataset.jobLabel || 'Job #' + logBtn.dataset.jobId); }
        if (!t.closest('#find-area-comune-results') && t.id !== 'find-area-comune') {
            hideFindAreaAutocomplete();
        }
    });

    var assignedSaveBtn = document.getElementById('assigned-save');
    if (assignedSaveBtn) assignedSaveBtn.addEventListener('click', async function () { await loadProperties(); });
    ['report-filter-comune','report-filter-foglio','report-filter-assigned'].forEach(function (id) { var el = document.getElementById(id); if (el) el.addEventListener('input', function () { applyReportFilters(); }); });

    document.querySelectorAll('[data-dashboard-tab]').forEach(function (button) {
        button.addEventListener('click', function () {
            var tab = button.getAttribute('data-dashboard-tab') || 'all';
            document.querySelectorAll('[data-dashboard-tab]').forEach(function (item) {
                item.classList.toggle('active', item === button);
            });
            document.querySelectorAll('[data-dashboard-section]').forEach(function (section) {
                var tokens = String(section.getAttribute('data-dashboard-section') || '').split(/\s+/);
                section.classList.toggle('d-none', tab !== 'all' && tokens.indexOf(tab) === -1);
            });
            if (state.dashboardMiniMap) {
                window.setTimeout(function () {
                    if (state.dashboardMiniMap) state.dashboardMiniMap.invalidateSize();
                }, 160);
            }
        });
    });

    document.querySelectorAll('[data-dashboard-filter-control]').forEach(function (control) {
        control.addEventListener('change', function () {
            if (state.dashboardPage) {
                loadDashboardStats(true).catch(function () {});
            }
        });
    });

    var dashboardExportBtn = document.getElementById('dashboard-export');
    if (dashboardExportBtn) {
        dashboardExportBtn.addEventListener('click', function () {
            var rows = (state.properties || []).map(function (property) {
                var owners = (property.owners || []).map(function (owner) {
                    return ((owner.cognome || '') + ' ' + (owner.nome || '')).trim();
                }).filter(Boolean).join(' | ');
                return [
                    property.provincia || '',
                    property.comune || '',
                    property.indirizzo || '',
                    property.foglio || '',
                    property.particella || '',
                    property.subalterno || '',
                    property.categoria || '',
                    property.titolarita || '',
                    owners
                ];
            });
            var csv = [['Provincia','Comune','Indirizzo','Foglio','Particella','Subalterno','Categoria','Titolarità','Intestatari']].concat(rows).map(function (row) {
                return row.map(function (value) {
                    return '\"' + String(value === null || value === undefined ? '' : value).replace(/\"/g, '\"\"') + '\"';
                }).join(';');
            }).join('\\n');
            var blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            var link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = 'analyticspro-export.csv';
            link.click();
            window.setTimeout(function () { URL.revokeObjectURL(link.href); }, 250);
        });
    }

    (function () {
        var zone = document.getElementById('import-drop-zone');
        if (!zone) return;
        zone.addEventListener('dragover',  function (e) { e.preventDefault(); zone.classList.add('dragover'); });
        zone.addEventListener('dragleave', function ()  { zone.classList.remove('dragover'); });
        zone.addEventListener('drop', function (e) {
            e.preventDefault(); zone.classList.remove('dragover');
            var validExts = ['.csv','.xlsx','.xls'];
            var files = Array.from(e.dataTransfer.files).filter(function (f) { return validExts.some(function (ext) { return f.name.toLowerCase().endsWith(ext); }); });
            if (!files.length) { alert('Nessun file valido. Formati: .csv, .xlsx, .xls'); return; }
            runImport(files).catch(function (e2) { if (state.overlay) state.overlay.hide(); alert(e2.message); });
        });
        zone.addEventListener('click',   function (e) { if (!e.target.closest('label')) { var inp=document.getElementById('import-files'); if(inp)inp.click(); } });
        zone.addEventListener('keydown', function (e) { if (e.key==='Enter'||e.key===' ') { e.preventDefault(); var inp=document.getElementById('import-files'); if(inp)inp.click(); } });
    })();

    ensureSharedModals();
    initManualRecordModal();
    initFindArea();
    initCadastralUi();

    if (state.propertiesEndpoint) {
        loadProperties().catch(function (error) { alert(error.message); });
    }

    if (document.getElementById('ade-jobs')) {
        if (!state.adeManualFilesEndpoint && state.adeJobsEndpoint) {
            try {
                var jobsUrl = new URL(state.adeJobsEndpoint, window.location.origin);
                var rp = jobsUrl.pathname.replace(/ade_jobs\.php$/, 'ade_manual_files.php');
                if (rp !== jobsUrl.pathname) { jobsUrl.pathname = rp; jobsUrl.search = ''; jobsUrl.hash = ''; state.adeManualFilesEndpoint = jobsUrl.toString(); }
                else { var fe = state.adeJobsEndpoint.replace(/ade_jobs\.php(?:\?.*)?(?:#.*)?$/, 'ade_manual_files.php'); state.adeManualFilesEndpoint = fe !== state.adeJobsEndpoint ? fe : ''; }
            } catch(_) { var fe2 = state.adeJobsEndpoint.replace(/ade_jobs\.php(?:\?.*)?(?:#.*)?$/, 'ade_manual_files.php'); state.adeManualFilesEndpoint = fe2 !== state.adeJobsEndpoint ? fe2 : ''; }
        }
        refreshAdeJobs().catch(function(){});
        loadAdeServerFiles({ type:'zip', listId:'ade-server-files-list', selectAllId:'ade-server-select-all', submitId:'ade-server-submit', emptyLabel:'Nessun file ZIP presente in <code>storage/manual_upload/</code>.' }).catch(function(){});
        var tsb = document.getElementById('tab-server-btn'); if (tsb) tsb.addEventListener('shown.bs.tab', function () { loadAdeServerFiles({ type:'zip', listId:'ade-server-files-list', selectAllId:'ade-server-select-all', submitId:'ade-server-submit', emptyLabel:'Nessun file ZIP presente in <code>storage/manual_upload/</code>.' }).catch(function(){}); });
        var tqb = document.getElementById('tab-sql-btn');    if (tqb) tqb.addEventListener('shown.bs.tab', function () { loadAdeServerFiles({ type:'sql', listId:'ade-server-sql-files-list', selectAllId:'ade-server-sql-select-all', submitId:'ade-server-sql-submit', emptyLabel:'Nessun file SQL presente in <code>storage/manual_upload/</code>.' }).catch(function(){}); });
        var adePollingInterval = setInterval(function () { refreshAdeJobs().catch(function () { clearInterval(adePollingInterval); }); }, 5000);
    }

    (function () {
        if (state.missingCoordinatesStatsEndpoint && document.getElementById('missing-coordinates-summary')) {
            loadMissingCoordinatesStats().catch(function (error) {
                var summary = document.getElementById('missing-coordinates-summary');
                if (summary) {
                    summary.textContent = 'Impossibile caricare il contatore coordinate mancanti.';
                }
                console.error('[missing_coordinates_stats]', error);
            });
        }
        var btn = document.getElementById('rigenera-coordinate-btn');
        if (!btn || !state.enrichChunkEndpoint) return;
        btn.addEventListener('click', async function () {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>In corso...';
            importLoggerReset();
            setWeightedImportPhase('enrich', 0, 'Rigenera coordinate...');
            importLog('info', 'Rigenera coordinate mancanti avviato.');
            try {
                await enrichChunkLoop(0);
            } catch (error) {
                importLog('error', 'Rigenerazione coordinate fallita: ' + (error && error.message ? error.message : 'Errore sconosciuto'));
                finalizeImportUi('danger', 'Rigenerazione coordinate fallita: ' + (error && error.message ? error.message : 'Errore sconosciuto'));
            } finally {
                btn.disabled = false;
                btn.innerHTML = '<i class="bi bi-geo-alt me-1"></i>Rigenera coordinate mancanti';
            }
        });
    })();

})();
