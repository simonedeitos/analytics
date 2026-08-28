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
        in_vendita_noi: 'In Vendita NOI',
        in_vendita_altri: 'In Vendita ALTRI',
        altro: 'Altro',
    };

    const state = {
        csrfToken: document.querySelector('meta[name="csrf-token"]')?.content || '',
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
        adeJobsEndpoint: root.dataset.adeJobsEndpoint || '',
        adeManualFilesEndpoint: root.dataset.adeManualFilesEndpoint || '',
        properties: [],
        subusers: [],
        map: null,
        markers: null,
        charts: {},
        tables: {},
        overlay: importOverlayEl ? new bootstrap.Modal(importOverlayEl) : null,
        mapStatiFilter: Object.keys(STATE_OPTIONS),
    };

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>'"]/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[char]));
    }

    function parseDob(raw) {
        if (!raw) return null;
        const parts = String(raw).split('-');
        if (parts.length === 3) return new Date(Number(parts[0]), Number(parts[1]) - 1, Number(parts[2]));
        return null;
    }

    function calcAge(raw) {
        const dob = parseDob(raw);
        if (!dob || Number.isNaN(dob.getTime())) return null;
        const now = new Date();
        let age = now.getFullYear() - dob.getFullYear();
        const m = now.getMonth() - dob.getMonth();
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
        return {
            non_interessato: '#6c757d',
            interessato: '#198754',
            contattato: '#0dcaf0',
            da_contattare: '#0d6efd',
            in_vendita_noi: '#fd7e14',
            in_vendita_altri: '#dc3545',
            altro: '#6f42c1',
        }[stateKey] || '#0d6efd';
    }

    async function api(url, options = {}) {
        const allowErrorPayload = options.allowErrorPayload === true;
        const requestOptions = { ...options };
        delete requestOptions.allowErrorPayload;

        const response = await fetch(url, requestOptions);
        if (response.status === 401) {
            const loginUrl = (state.adeJobsEndpoint || state.propertiesEndpoint || '').replace(/\/api\/.*$/, '/login.php') || 'login.php';
            window.location.href = loginUrl;
            return new Promise(() => {});
        }
        const payload = await response.json();
        if (!response.ok || payload.ok === false) {
            if (allowErrorPayload) return payload;
            const error = new Error(payload.error || 'Operazione non riuscita');
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
        return `${url}${url.includes('?') ? '&' : '?'}tenant_id=${encodeURIComponent(state.selectedTenant)}`;
    }

    async function loadProperties() {
        const [allPayload, assignedPayload] = await Promise.all([
            api(withTenant(`${state.propertiesEndpoint}?mode=all`)),
            api(withTenant(`${state.propertiesEndpoint}?mode=assigned${state.role !== 'subuser' ? '&subuser_id=' : ''}`)),
        ]);
        state.properties = allPayload.properties || [];
        state.subusers = allPayload.subusers || [];
        state.assignedProperties = assignedPayload.properties || [];
        updateKpis();
        renderMap();
        renderAssignedTable();
        if (state.canViewReports || state.role !== 'subuser') renderReportTable();
        if (state.canViewAnalytics || state.role !== 'subuser') renderCharts();
        populateAssignedSubuserFilter();
    }

    function updateKpis() {
        const ownerCount = state.properties.reduce((sum, property) => sum + (property.owners?.length || 0), 0);
        const phoneCount = state.canViewPhone
            ? state.properties.reduce((sum, property) => sum + (property.owners || []).filter(owner => owner.telefono).length, 0)
            : 0;
        const assignedCount = state.role === 'subuser'
            ? state.assignedProperties.length
            : state.properties.filter(property => (property.assignments || []).length > 0).length;

        const setKpi = (key, value) => {
            const el = document.querySelector(`[data-kpi="${key}"]`);
            if (el) el.textContent = value;
        };
        setKpi('properties', state.properties.length);
        setKpi('owners', ownerCount);
        if (state.canViewPhone) {
            setKpi('phones', phoneCount);
        } else {
            const phoneKpiEl = document.querySelector('[data-kpi="phones"]');
            if (phoneKpiEl) {
                const card = phoneKpiEl.closest('.card, .kpi-card, [class*="kpi"]');
                if (card) card.remove();
            }
        }
        setKpi('assigned', assignedCount);
    }

    function buildOwnerSummary(property) {
        const names = (property.owners || []).map(owner => {
            const fullName = `${owner.cognome || ''} ${owner.nome || ''}`.trim();
            return fullName || 'Intestatario';
        });
        return escapeHtml(names.join(', '));
    }

    function assignmentNames(property) {
        return (property.assignments || []).map(assignment => assignment.subuser_name).filter(Boolean);
    }

    function assignmentNamesLabel(property) {
        const names = assignmentNames(property);
        return names.length ? names.join(', ') : 'Non assegnato';
    }

    function buildAssignmentSummary(property) {
        const names = assignmentNames(property);
        const canManage = state.role !== 'subuser' && state.subusers.length > 0;
        const action = canManage
            ? `<button type="button" class="btn btn-outline-secondary btn-sm ms-1 assignment-picker-btn" data-property-id="${property.id}" title="Assegna/Modifica assegnazioni"><i class="bi bi-person-plus me-1"></i>Assegna</button>`
            : '';
        if (!names.length) {
            return `<span class="small text-muted">Non assegnato</span>${action}`;
        }
        return `<span class="small">${escapeHtml(names.join(', '))}</span>${action}`;
    }

    function buildSelectOptions(selected) {
        return Object.entries(STATE_OPTIONS).map(([value, label]) => `<option value="${value}" ${value === selected ? 'selected' : ''}>${escapeHtml(label)}</option>`).join('');
    }

    function buildAssignmentSelect(property) {
        if (state.role === 'subuser' || !state.subusers.length) return buildAssignmentSummary(property);
        const selected = new Set((property.assignments || []).map(item => Number(item.subuser_id)));
        return `
            <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
                <span class="small">${buildAssignmentSummary(property)}</span>
                <button type="button" class="btn btn-outline-secondary btn-sm assignment-picker-btn" data-property-id="${property.id}" title="Gestisci assegnazioni">
                    <i class="bi bi-person-plus"></i>
                </button>
            </div>
            <select class="form-select form-select-sm small-select assignment-select d-none" multiple>${state.subusers.map(subuser => `<option value="${subuser.id}" ${selected.has(Number(subuser.id)) ? 'selected' : ''}>${escapeHtml(`${subuser.nome} ${subuser.cognome}`)}</option>`).join('')}</select>
        `;
    }

    function editableColumns(property) {
        const disabled = property.can_edit ? '' : 'disabled';
        return `
            <button type="button" class="btn btn-outline-primary btn-sm open-editor-modal" data-property-id="${property.id}" ${disabled}>
                <i class="bi bi-pencil-square me-1"></i>Modifica
            </button>
        `;
    }

    function detailColumn(property) {
        return `
            <button type="button" class="btn btn-outline-secondary btn-sm open-detail-modal" data-property-id="${property.id}">
                <i class="bi bi-eye me-1"></i>Dettaglio
            </button>
        `;
    }

    function unitLabel(property) {
        const sub = property.subalterno ? `/${property.subalterno}` : '';
        return `F.${property.foglio || '—'} P.${property.particella || '—'}${sub}`;
    }

    function buildTableData(properties, context = 'default') {
        return properties.map(property => ({
            id: property.id,
            tenant: property.tenant_name || '',
            comune: property.comune || '',
            provincia: property.provincia || '',
            indirizzo: `${property.indirizzo || ''} ${property.civico || ''}`.trim(),
            unita: unitLabel(property),
            stato: STATE_OPTIONS[property.stato ?? ''] ?? (property.stato || ''),
            colore: `<span class="color-dot" style="background:${escapeHtml(property.colore_marker || '#0d6efd')}"></span>`,
            owners: buildOwnerSummary(property),
            assignmentsText: escapeHtml(assignmentNamesLabel(property)),
            assignments: buildAssignmentSummary(property),
            detail: detailColumn(property),
            editor: editableColumns(property),
            raw: property,
        }));
    }

    function initDataTable(selector, rows, canExport, context = 'default') {
        if (state.tables[selector]) {
            state.tables[selector].destroy();
            $(selector).empty().append('<thead></thead><tfoot></tfoot><tbody></tbody>');
        }

        const reportColumns = [
            { title: 'Colore', data: 'colore' },
            { title: 'Comune', data: 'comune' },
            { title: 'Foglio/Particella/Sub', data: 'unita' },
            { title: 'Indirizzo', data: 'indirizzo' },
            { title: 'Intestatari', data: 'owners' },
            { title: 'Assegnati a', data: 'assignmentsText' },
            { title: 'Stato', data: 'stato' },
            { title: 'Dettaglio', data: 'detail' },
            { title: 'Modifica', data: 'editor' },
        ];
        const assignedColumns = [
            { title: 'Colore', data: 'colore' },
            { title: 'Comune', data: 'comune' },
            { title: 'Foglio/Particella/Sub', data: 'unita' },
            { title: 'Indirizzo', data: 'indirizzo' },
            { title: 'Intestatari', data: 'owners' },
            { title: 'Assegnati a', data: 'assignments' },
            { title: 'Modifica', data: 'editor' },
        ];
        const fullColumns = [
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
        ];
        const columns = context === 'report' ? reportColumns : (context === 'assigned' ? assignedColumns : fullColumns);

        const theadHtml = `<tr>${columns.map(column => `<th>${column.title}</th>`).join('')}</tr>`;
        const useFooterFilters = context !== 'report';
        const tfootHtml = `<tr>${columns.map(column => `<th>${(!useFooterFilters || column.title === 'Modifica' || column.title === 'Dettaglio') ? '' : `<input type="text" class="form-control form-control-sm" placeholder="Filtra ${column.title}">`}</th>`).join('')}</tr>`;
        $(`${selector} thead`).html(theadHtml);
        $(`${selector} tfoot`).html(tfootHtml);

        const buttons = canExport ? [{ extend: 'csvHtml5', text: 'CSV' }, { extend: 'excelHtml5', text: 'Excel' }] : [];
        state.tables[selector] = $(selector).DataTable({
            data: rows,
            columns,
            pageLength: 25,
            order: [],
            language: { url: 'https://cdn.datatables.net/plug-ins/1.13.8/i18n/it-IT.json' },
            dom: buttons.length ? 'Bfrtip' : 'frtip',
            buttons,
            columnDefs: [{ targets: [0, columns.length - 1], orderable: false }],
        });

        if (!useFooterFilters) return;

        state.tables[selector].columns().every(function (index) {
            const input = $(`${selector} tfoot th`).eq(index).find('input');
            if (!input.length) return;
            input.on('keyup change clear', () => {
                if (state.tables[selector].column(index).search() !== input.val()) {
                    state.tables[selector].column(index).search(input.val()).draw();
                }
            });
        });
    }

    function getAssignedPropertiesForDisplay() {
        const assignmentFilter = document.getElementById('assigned-assignment-filter')?.value || 'all';
        if (assignmentFilter === 'assigned') return state.assignedProperties.filter(property => property.is_assigned);
        if (assignmentFilter === 'unassigned') return state.assignedProperties.filter(property => !property.is_assigned);
        return state.assignedProperties;
    }

    function renderAssignedTable() {
        if (!document.getElementById('assigned-table')) return;
        initDataTable('#assigned-table', buildTableData(getAssignedPropertiesForDisplay(), 'assigned'), state.canExport || state.role !== 'subuser', 'assigned');
    }

    function renderReportTable() {
        if (!document.getElementById('report-table')) return;
        initDataTable('#report-table', buildTableData(state.properties, 'report'), state.role !== 'subuser', 'report');
        hydrateReportFilters();
        applyReportFilters();
    }

    function hydrateReportFilters() {
        const colorSelect = document.getElementById('report-filter-color');
        const stateSelect = document.getElementById('report-filter-stato');
        if (colorSelect) {
            const selected = colorSelect.value;
            const colors = Array.from(new Set(state.properties.map(property => property.colore_marker).filter(Boolean)));
            colorSelect.innerHTML = '<option value="">Tutti</option>' + colors
                .map(color => `<option value="${escapeHtml(String(color))}">${escapeHtml(String(color))}</option>`)
                .join('');
            colorSelect.value = selected && colors.includes(selected) ? selected : '';
        }
        if (stateSelect) {
            const selected = stateSelect.value;
            stateSelect.innerHTML = '<option value="">Tutti</option>' + Object.entries(STATE_OPTIONS)
                .map(([value, label]) => `<option value="${escapeHtml(label)}">${escapeHtml(label)}</option>`)
                .join('');
            stateSelect.value = selected;
        }
    }

    function applyReportFilters() {
        const table = state.tables['#report-table'];
        if (!table) return;
        const colorValue = document.getElementById('report-filter-color')?.value || '';
        const comuneValue = document.getElementById('report-filter-comune')?.value || '';
        const foglioValue = document.getElementById('report-filter-foglio')?.value || '';
        const statoValue = document.getElementById('report-filter-stato')?.value || '';
        const assignedValue = document.getElementById('report-filter-assigned')?.value || '';

        table.column(0).search(colorValue ? `background:${colorValue}` : '', true, false);
        table.column(1).search(comuneValue);
        table.column(2).search(foglioValue);
        table.column(5).search(assignedValue);
        table.column(6).search(statoValue);
        table.draw();
    }

    function renderMap() {
        const container = document.getElementById('map-fullpage') || document.getElementById('map-container');
        if (!container) return;
        if (!state.map) {
            state.map = L.map(container).setView([41.9, 12.5], 6);
            state.clusterIconCache = new Map();

            const layerStreets = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
                maxZoom: 19,
            });
            const layerSatellite = L.tileLayer(
                'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
                {
                    attribution: 'Tiles &copy; Esri &mdash; Source: Esri, i-cubed, USDA, USGS, AEX, GeoEye, Getmapping, Aerogrid, IGN, IGP, UPR-EGP, and the GIS User Community',
                    maxZoom: 19,
                }
            );
            layerStreets.addTo(state.map);
            L.control.layers(
                { 'Strade': layerStreets, 'Satellite': layerSatellite },
                {},
                { position: 'topright' }
            ).addTo(state.map);

            state.markers = L.markerClusterGroup({
                iconCreateFunction: function (cluster) {
                    const markers = cluster.getAllChildMarkers();
                    const total = markers.length;
                    if (!total) {
                        return L.divIcon({ html: '', className: 'analyticspro-cluster-icon', iconSize: L.point(40, 40) });
                    }
                    const counts = {};
                    for (const marker of markers) {
                        const color = marker.options?.fillColor
                            || marker.options?.color
                            || marker.options?.icon?.options?.markerColor
                            || '#808080';
                        counts[color] = (counts[color] || 0) + 1;
                    }
                    const key = Object.entries(counts)
                        .sort((a, b) => String(a[0]).localeCompare(String(b[0])))
                        .map(([color, count]) => `${color}:${count}`)
                        .join('|');
                    if (state.clusterIconCache?.has(key)) {
                        return state.clusterIconCache.get(key);
                    }
                    const radius = 1;
                    let startAngle = -Math.PI / 2;
                    let paths = '';
                    const entries = Object.entries(counts);
                    entries.forEach(([color, count], index) => {
                        const pct = count / total;
                        const endAngle = startAngle + (Math.PI * 2 * pct);
                        const safeColor = /^#[0-9a-fA-F]{3,8}$/.test(color) ? color : '#808080';
                        if (entries.length === 1) {
                            paths += `<circle cx="0" cy="0" r="${radius}" fill="${safeColor}"></circle>`;
                            return;
                        }
                        const x1 = Math.cos(startAngle) * radius;
                        const y1 = Math.sin(startAngle) * radius;
                        const x2 = Math.cos(endAngle) * radius;
                        const y2 = Math.sin(endAngle) * radius;
                        const largeArc = pct > 0.5 ? 1 : 0;
                        paths += `<path d="M 0 0 L ${x1.toFixed(6)} ${y1.toFixed(6)} A ${radius} ${radius} 0 ${largeArc} 1 ${x2.toFixed(6)} ${y2.toFixed(6)} Z" fill="${safeColor}"></path>`;
                        startAngle = endAngle;
                    });
                    const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="40" height="40" viewBox="-1.05 -1.05 2.1 2.1"><circle cx="0" cy="0" r="1" fill="rgba(255,255,255,.25)"></circle>${paths}<circle cx="0" cy="0" r="0.4" fill="rgba(0,0,0,.35)"></circle><text x="0" y="0.1" text-anchor="middle" font-size="0.6" font-weight="700" fill="#fff">${total}</text></svg>`;
                    const icon = L.divIcon({
                        html: svg,
                        className: 'analyticspro-cluster-icon',
                        iconSize: L.point(40, 40),
                        iconAnchor: L.point(20, 20),
                    });
                    state.clusterIconCache?.set(key, icon);
                    return icon;
                },
            });
            state.map.addLayer(state.markers);
        }

        state.markers.clearLayers();
        const groups = new Map();
        state.properties.forEach(property => {
            if (!property.lat || !property.lng) return;
            const lat = Number(property.lat);
            const lng = Number(property.lng);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
            const statoVal = property.stato ?? '';
            if (!state.mapStatiFilter.includes(statoVal)) return;
            const key = `${lat.toFixed(7)}|${lng.toFixed(7)}`;
            if (!groups.has(key)) groups.set(key, []);
            groups.get(key).push(property);
        });
        const points = [];
        groups.forEach(group => {
            const first = group[0];
            const marker = L.circleMarker([Number(first.lat), Number(first.lng)], {
                radius: 8,
                color: first.colore_marker || '#2A519F',
                fillColor: first.colore_marker || '#2A519F',
                fillOpacity: 0.9,
                weight: 2,
            });
            marker.bindPopup(buildPopupHtml(group), { maxWidth: 420 });
            state.markers.addLayer(marker);
            points.push(first);
        });
        if (points.length) {
            state.map.fitBounds(state.markers.getBounds().pad(0.2));
        }
        setTimeout(() => state.map.invalidateSize(), 150);
        setTimeout(() => state.map?.invalidateSize(), 600);
    }

    function ownerDetailRows(property) {
        if (!(property.owners || []).length) {
            return '<div class="text-muted small">Nessun intestatario disponibile.</div>';
        }
        return (property.owners || []).map(owner => {
            const fullName = `${owner.cognome || ''} ${owner.nome || ''}`.trim() || 'Intestatario';
            const identity = [owner.codice_fiscale ? `CF/P.IVA: ${owner.codice_fiscale}` : null, owner.email ? `✉ ${owner.email}` : null, owner.indirizzo ? `📍 ${owner.indirizzo}` : null]
                .filter(Boolean)
                .join(' | ');
            const profile = [owner.data_nascita ? `Nato il: ${owner.data_nascita}` : null, owner.genere ? `Genere: ${owner.genere}` : null, state.canViewPhone && owner.telefono ? `☎ ${owner.telefono}` : null]
                .filter(Boolean)
                .join(' | ');
            return `
                <div class="property-owner-row">
                    <div class="fw-semibold">${escapeHtml(fullName)}${property.quota ? ` (${escapeHtml(property.quota)})` : ''}${property.titolarita ? ` – ${escapeHtml(property.titolarita)}` : ''}</div>
                    ${identity ? `<div class="owner-meta">${escapeHtml(identity)}</div>` : ''}
                    ${profile ? `<div class="owner-meta">${escapeHtml(profile)}</div>` : ''}
                </div>
            `;
        }).join('');
    }

    function propertyNotesHtml(property) {
        return (property.notes || []).length
            ? (property.notes || []).map(note => `<div class="small mb-1"><strong>${escapeHtml(note.author_name_snapshot || '')}</strong> · ${escapeHtml(note.created_at || '')}<br>${escapeHtml(note.testo || '')}</div>`).join('')
            : '<span class="text-muted small">Nessuna nota</span>';
    }

    function buildPropertyCardHtml(property, options = {}) {
        const mapMode = options.mapMode === true;
        const addressLabel = `${property.indirizzo || ''} ${property.civico || ''}`.trim() || 'Immobile';
        const assignmentText = assignmentNames(property).length ? escapeHtml(assignmentNames(property).join(', ')) : '<span class="text-muted">Non assegnato</span>';
        const assignmentBtn = state.role !== 'subuser' && state.subusers.length
            ? `<button type="button" class="btn btn-outline-secondary btn-sm assignment-picker-btn" data-property-id="${property.id}" title="Assegna/Modifica assegnazioni"><i class="bi bi-person-plus"></i></button>`
            : '';
        const editAction = property.can_edit
            ? `<button type="button" class="btn btn-outline-primary btn-sm open-editor-modal" data-property-id="${property.id}"><i class="bi bi-pencil-square me-1"></i>Modifica</button>`
            : '<div class="small text-warning-emphasis">⚠️ Non hai i permessi per modificare questo marker.</div>';
        const closeAction = mapMode
            ? '<button type="button" class="btn btn-outline-secondary btn-sm close-map-popup"><i class="bi bi-x-lg me-1"></i>Chiudi</button>'
            : '<button type="button" class="btn btn-outline-secondary btn-sm close-detail-modal"><i class="bi bi-x-lg me-1"></i>Chiudi</button>';

        return `
            <div class="card map-popup-professional mb-2" data-property-id="${property.id}">
                <div class="card-header py-2">
                    <div class="fw-semibold">🏠 ${escapeHtml(addressLabel)} – ${escapeHtml(unitLabel(property))}</div>
                    <div class="small mt-1"><span class="color-dot me-1" style="background:${escapeHtml(property.colore_marker || '#0d6efd')}"></span>${escapeHtml(property.comune || '')}</div>
                </div>
                <div class="card-body py-2">
                    <div class="small fw-semibold text-uppercase text-muted mb-2">Intestatari</div>
                    ${ownerDetailRows(property)}
                    <hr class="my-2">
                    <div class="small"><strong>Note</strong><div class="mt-1">${propertyNotesHtml(property)}</div></div>
                    <hr class="my-2">
                    <div class="d-flex align-items-center justify-content-between gap-2 flex-wrap">
                        <div class="small"><strong>Assegnati a:</strong> ${assignmentText}</div>
                        ${assignmentBtn}
                    </div>
                </div>
                <div class="card-footer py-2 d-flex align-items-center justify-content-between gap-2 flex-wrap">
                    ${editAction}
                    ${closeAction}
                </div>
            </div>
        `;
    }

    function buildPopupHtml(propertiesAtPoint) {
        const list = Array.isArray(propertiesAtPoint) ? propertiesAtPoint : [propertiesAtPoint];
        const cards = list.map(property => buildPropertyCardHtml(property, { mapMode: true })).join('');
        return `<div class="map-popup-professional">${cards}</div>`;
    }

    function destroyCharts() {
        Object.values(state.charts).forEach(chart => chart.destroy());
        state.charts = {};
    }

    function pieChart(id, labels, data) {
        const ctx = document.getElementById(id);
        if (!ctx) return;
        state.charts[id] = new Chart(ctx, {
            type: 'pie',
            data: { labels, datasets: [{ data }] },
            options: { responsive: true },
        });
    }

    function barChart(id, labels, data, label) {
        const ctx = document.getElementById(id);
        if (!ctx) return;
        state.charts[id] = new Chart(ctx, {
            type: 'bar',
            data: { labels, datasets: [{ label, data, backgroundColor: '#0d6efd' }] },
            options: { responsive: true, plugins: { legend: { display: false } } },
        });
    }

    function renderCharts() {
        destroyCharts();
        const owners = state.properties.flatMap(property => property.owners || []);
        const withPhone = state.canViewPhone ? owners.filter(owner => owner.telefono).length : 0;
        const withEmail = owners.filter(owner => owner.email).length;
        const withPiva  = owners.filter(owner => owner.tipo === 'azienda').length;
        const genders = owners.reduce((acc, owner) => { const key = owner.genere || 'N/D'; acc[key] = (acc[key] || 0) + 1; return acc; }, {});
        const provinces = state.properties.reduce((acc, property) => { const key = property.provincia || 'N/D'; acc[key] = (acc[key] || 0) + 1; return acc; }, {});
        const comuni = state.properties.reduce((acc, property) => { const key = property.comune || 'N/D'; acc[key] = (acc[key] || 0) + 1; return acc; }, {});
        const categories = state.properties.reduce((acc, property) => { const key = property.categoria || 'N/D'; acc[key] = (acc[key] || 0) + 1; return acc; }, {});
        const ownership = state.properties.reduce((acc, property) => { const key = property.titolarita || 'N/D'; acc[key] = (acc[key] || 0) + 1; return acc; }, {});
        const ages = owners.reduce((acc, owner) => { const key = ageGroup(calcAge(owner.data_nascita)); acc[key] = (acc[key] || 0) + 1; return acc; }, {});

        // Populate analytics KPI cards (present only on analitiche.php).
        const setKpiAnalytics = (key, value) => {
            const el = document.querySelector(`[data-kpi-analytics="${key}"]`);
            if (el) el.textContent = value.toLocaleString('it-IT');
        };
        setKpiAnalytics('total', owners.length);
        if (state.canViewPhone) {
            setKpiAnalytics('phone', withPhone);
        } else {
            const phoneKpiEl = document.querySelector('[data-kpi-analytics="phone"]');
            if (phoneKpiEl) {
                const card = phoneKpiEl.closest('.card, .kpi-card, [class*="kpi"]');
                if (card) card.remove();
            }
        }
        setKpiAnalytics('email', withEmail);
        setKpiAnalytics('piva',  withPiva);

        if (state.canViewPhone) {
            pieChart('chart-contacts', ['Con telefono', 'Con email', 'Senza contatti'], [withPhone, withEmail, Math.max(owners.length - Math.max(withPhone, withEmail), 0)]);
        } else {
            pieChart('chart-contacts', ['Con email', 'Senza contatti'], [withEmail, Math.max(owners.length - withEmail, 0)]);
        }
        pieChart('chart-gender', Object.keys(genders), Object.values(genders));
        barChart('chart-age', Object.keys(ages), Object.values(ages), 'Intestatari');
        pieChart('chart-province', Object.keys(provinces), Object.values(provinces));
        barChart('chart-comune', Object.keys(comuni).slice(0, 10), Object.values(comuni).slice(0, 10), 'Immobili');
        pieChart('chart-categoria', Object.keys(categories), Object.values(categories));
        pieChart('chart-titolarita', Object.keys(ownership), Object.values(ownership));
    }

    function populateAssignedSubuserFilter() {
        const select = document.getElementById('assigned-subuser-filter');
        if (!select) return;
        select.innerHTML = `<option value="">${state.role === 'subuser' ? 'Le mie assegnazioni' : 'Tutte le assegnazioni'}</option>` + state.subusers.map(subuser => `<option value="${subuser.id}">${escapeHtml(`${subuser.nome} ${subuser.cognome}`)}</option>`).join('');
    }

    function findPropertyById(propertyId) {
        return state.properties.find(item => Number(item.id) === Number(propertyId))
            || state.assignedProperties?.find(item => Number(item.id) === Number(propertyId))
            || null;
    }

    async function savePropertyPayload(payload) {
        await api(state.propertyUpdateEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: state.csrfToken,
                ...payload,
            }),
        });
        await loadProperties();
    }

    async function saveProperty(button) {
        const wrapper = button.closest('[data-property-id]') || button.closest('tr');
        const propertyId = Number(button.dataset.propertyId || wrapper?.dataset.propertyId || 0);
        if (!propertyId) return;
        const property = findPropertyById(propertyId);
        const stateSelect = wrapper?.querySelector('.state-select');
        const colorInput = wrapper?.querySelector('.color-input');
        const noteInput = wrapper?.querySelector('.note-input');
        const customStateInput = wrapper?.querySelector('.custom-state-input');
        const assignmentSelect = wrapper?.querySelector('.assignment-select');
        const assignments = assignmentSelect ? Array.from(assignmentSelect.selectedOptions).map(option => Number(option.value)) : undefined;

        button.disabled = true;
        try {
            await savePropertyPayload({
                property_id: propertyId,
                stato: stateSelect?.value || property?.stato,
                stato_personalizzato: customStateInput?.value ?? (property?.stato_personalizzato || ''),
                colore_marker: colorInput?.value || property?.colore_marker,
                note: noteInput?.value || '',
                assignments,
            });
        } catch (error) {
            alert(error.message);
        } finally {
            button.disabled = false;
        }
    }

    async function parseFiles(files) {
        const parsedRows = [];
        for (const file of files) {
            const buffer = await file.arrayBuffer();
            const workbook = XLSX.read(buffer, { type: 'array', raw: false, dateNF: 'yyyy-mm-dd' });
            const sheet = workbook.Sheets[workbook.SheetNames[0]];
            const rows = XLSX.utils.sheet_to_json(sheet, { header: 1, defval: '', blankrows: false, raw: false });
            if (!rows.length) continue;
            const headers = rows[0].map(value => String(value).trim());
            for (let index = 1; index < rows.length; index++) {
                const current = rows[index];
                const payload = {};
                headers.forEach((header, cellIndex) => { payload[header] = current[cellIndex] !== undefined ? String(current[cellIndex]).trim() : ''; });
                parsedRows.push(payload);
            }
        }
        return parsedRows;
    }

    function importLoggerReset() {
        const container = document.getElementById('enrichment-status-container');
        const phase = document.getElementById('import-phase');
        const bar = document.getElementById('import-progress-bar');
        const text = document.getElementById('import-progress-text');
        const log = document.getElementById('import-log-console');
        const reportEl = document.getElementById('enrichment-report');
        if (container) container.style.display = '';
        if (phase) phase.textContent = 'Lettura file';
        if (bar) bar.style.width = '0%';
        if (text) text.textContent = 'Preparazione import...';
        if (log) log.textContent = '';
        if (reportEl) {
            reportEl.className = 'small d-none';
            reportEl.innerHTML = '';
        }
    }

    function importLog(level, message) {
        const log = document.getElementById('import-log-console');
        if (!log) return;
        const ts = new Date().toLocaleTimeString('it-IT', { hour12: false });
        log.textContent += `[${ts}] ${String(level || 'info').toUpperCase().padEnd(7)} ${message}\n`;
        log.scrollTop = log.scrollHeight;
    }

    function setImportPhase(phaseLabel, percent, statusText = '') {
        const phase = document.getElementById('import-phase');
        const bar = document.getElementById('import-progress-bar');
        const text = document.getElementById('import-progress-text');
        if (phase) phase.textContent = phaseLabel;
        if (bar && Number.isFinite(percent)) {
            const pct = Math.max(0, Math.min(100, Number(percent)));
            bar.style.width = `${pct}%`;
        }
        if (text && statusText) text.textContent = statusText;
    }

    async function runImport(files) {
        importLoggerReset();
        importLog('info', 'Fase Lettura file avviata');
        const rows = await parseFiles(files);
        if (!rows.length) {
            setImportPhase('Completato', 100, 'Nessuna riga valida trovata');
            importLog('warning', 'Nessuna riga valida trovata nei file selezionati.');
            return;
        }
        setImportPhase('Lettura file', 10, `Righe lette: ${rows.length}`);
        importLog('info', `Righe lette: ${rows.length}`);

        setImportPhase('Analisi duplicati', 20, 'Analisi duplicati in corso...');
        const analysis = await api(state.importEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: state.csrfToken, mode: 'analyze', rows }),
        });
        importLog('info', `Duplicati rilevati: ${(analysis.conflicts || []).length}`);

        const decisions = {};
        for (const conflict of analysis.conflicts || []) {
            const confirmUpdate = window.confirm(`Duplicato per ${conflict.comune} F.${conflict.foglio} P.${conflict.particella}${conflict.subalterno ? `/${conflict.subalterno}` : ''}.\nNuovo intestatario: ${conflict.incoming_owner}.\nVuoi aggiornare il dato con il nuovo intestatario?`);
            decisions[conflict.row_index] = confirmUpdate ? 'updated' : 'kept_old';
        }

        setImportPhase('Salvataggio dati', 45, 'Salvataggio dati in corso...');
        importLog('info', 'Fase Salvataggio dati avviata');
        const processPayload = await api(state.importEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                csrf_token: state.csrfToken,
                mode: 'process',
                filename: Array.from(files).map(file => file.name).join(', '),
                decisions,
                rows,
            }),
        });
        setImportPhase('Geolocalizzazione', 80, 'Geolocalizzazione particelle in corso...');
        importLog('info', `Righe salvate: ${processPayload.saved_rows ?? processPayload.total_rows ?? rows.length}`);
        importLog('info', `Particelle geolocalizzate nella richiesta: ${processPayload.geolocated_parcels ?? 0}`);
        await loadProperties();
        renderEnrichmentReport({
            coord_source: processPayload.coord_source || {},
            failure_codes: processPayload.failure_codes || {},
            unresolved_rows: processPayload.unresolved_rows || [],
            truncated: !!processPayload.unresolved_truncated,
        });
        if (processPayload.batch_id) {
            if (processPayload.enrichment_done) {
                setImportPhase('Completato', 100, `Completato: ${processPayload.saved_rows ?? rows.length} righe salvate`);
                importLog('info', 'Geolocalizzazione completata nella stessa richiesta.');
            } else {
                importLog('warning', `Particelle residue: ${processPayload.remaining_unique_parcels ?? 0}. Completo ora con la stessa logica di "Rigenera coordinate".`);
                await enrichChunkLoop(processPayload.batch_id);
            }
        }
    }

    async function pollImport(batchId) {
        while (true) {
            const payload = await api(`${state.importProgressEndpoint}?batch_id=${batchId}`);
            const batch = payload.batch;
            const percent = batch.progress_percent || 0;
            const progressBar = document.getElementById('import-progress-bar');
            const progressText = document.getElementById('import-progress-text');
            if (progressBar) progressBar.style.width = `${percent}%`;
            if (progressText) progressText.textContent = `${percent}% · ${batch.processed_rows}/${batch.total_rows} righe`;
            if (batch.status === 'completed') return;
            if (batch.status === 'failed') throw new Error(batch.error_message || 'Import fallito');
            await new Promise(resolve => setTimeout(resolve, 1000));
        }
    }

    async function pollEnrichment(batchId) {
        // Show the enrichment status container if present in the page.
        const container = document.getElementById('enrichment-status-container');
        const bar       = document.getElementById('import-progress-bar');
        const text      = document.getElementById('import-progress-text');
        const reportEl  = document.getElementById('enrichment-report');
        if (container) container.style.display = '';
        if (reportEl) {
            reportEl.className = 'small mt-2 d-none';
            reportEl.innerHTML = '';
        }

        const maxIterations = 240; // ~10 minutes at 2.5 s per poll
        let   iterations    = 0;
        let   lastProcessed = -1;
        let   stalledSince  = 0; // polls with no progress change

        while (iterations < maxIterations) {
            iterations++;
            let batch;
            try {
                const payload = await api(`${state.importProgressEndpoint}?batch_id=${batchId}`);
                batch = payload.batch;
            } catch {
                await new Promise(resolve => setTimeout(resolve, 3000));
                continue;
            }

            const status    = batch.enrichment_status    ?? null;
            const processed = batch.enrichment_processed ?? 0;
            const total     = batch.enrichment_total     ?? 0;
            const pct       = total > 0 ? Math.round((processed / total) * 100) : 0;
            renderEnrichmentReport(batch.enrichment_report);

            if (bar)  bar.style.width    = `${Math.max(80, pct)}%`;
            if (text) text.textContent   = `Geolocalizzazione: ${processed}/${total} marker (${pct}%)`;

            if (status === 'completed') {
                if (text) text.textContent = `Geolocalizzazione completata: ${processed}/${total} marker.`;
                if (bar)  bar.style.width  = '100%';
                setImportPhase('Completato', 100, `Completato: ${processed}/${total} marker`);
                try { await loadProperties(); } catch { /* ignore */ }
                if (container) setTimeout(() => { container.style.display = 'none'; }, 4000);
                return;
            }

            if (status === 'failed') {
                if (text) {
                    text.textContent = 'Geolocalizzazione non riuscita. Verifica la configurazione GML / Zornade / WFS nel file .env, oppure usa "Rigenera coordinate mancanti" per riprovare.';
                    text.classList.add('text-danger');
                }
                importLog('error', 'Geolocalizzazione non riuscita.');
                if (bar) bar.classList.replace('bg-primary', 'bg-danger');
                return;
            }

            // Watchdog anti-stallo: se dopo 6 poll lo stato è ancora 'pending' e
            // non c'è nessun progresso, il worker in background non si è avviato.
            // Passiamo automaticamente alla modalità chunk sincrona.
            if (status === 'pending' && processed === 0) {
                stalledSince++;
                if (stalledSince >= 6 && state.enrichChunkEndpoint) {
                    if (text) text.textContent = 'Worker background non disponibile — geolocalizzazione sincrona in corso...';
                    importLog('warning', 'Worker background non disponibile, passo a chunk sincrono.');
                    enrichChunkLoop(batchId).catch(() => {});
                    return; // lascia enrichChunkLoop guidare la UI
                }
            } else if (processed !== lastProcessed) {
                stalledSince  = 0;
                lastProcessed = processed;
            }

            // Still processing — poll again after 2.5 s.
            await new Promise(resolve => setTimeout(resolve, 2500));
        }

        // Max iterations reached — stop polling to avoid infinite background requests.
        if (text) text.textContent = 'Timeout polling geolocalizzazione. Usa "Rigenera coordinate mancanti" per riprovare i marker non risolti.';
    }

    /**
     * Modalità sincrona: chiama ripetutamente enrich_chunk fino al completamento.
     * Usata quando il worker in background non è disponibile.
     */
    async function enrichChunkLoop(batchId) {
        const container = document.getElementById('enrichment-status-container');
        const bar       = document.getElementById('import-progress-bar');
        const text      = document.getElementById('import-progress-text');
        const reportEl  = document.getElementById('enrichment-report');
        if (container) container.style.display = '';
        if (reportEl) {
            reportEl.className = 'small mt-2 d-none';
            reportEl.innerHTML = '';
        }

        const maxChunks = 500; // sicurezza — max 500 * 25 = 12 500 particelle
        let   calls     = 0;

        setImportPhase('Geolocalizzazione', 80, 'Geolocalizzazione sincrona in corso...');
        importLog('info', 'Avvio fallback chunk sincrono');

        while (calls < maxChunks) {
            calls++;
            let result;
            try {
                result = await api(`${state.enrichChunkEndpoint}?batch_id=${batchId}&limit=25`, { allowErrorPayload: true });
            } catch (fetchErr) {
                // Errore di rete transitorio: riprova dopo una pausa
                await new Promise(resolve => setTimeout(resolve, 2000));
                continue;
            }

            // Ferma il loop su errori non recuperabili (non riprovare all'infinito)
            if (result.ok === false) {
                const errCode = result.error_code ?? 'unknown';
                if (errCode === 'transient') {
                    await new Promise(resolve => setTimeout(resolve, 2000));
                    continue;
                }
                if (text) {
                    text.textContent = `Errore enrichment [${errCode}]: ${result.error ?? 'Errore sconosciuto'}`;
                    text.classList.add('text-danger');
                }
                importLog('error', `Errore non recuperabile [${errCode}]: ${result.error ?? 'Errore sconosciuto'}`);
                if (bar) bar.classList.replace('bg-primary', 'bg-danger');
                return;
            }

            const processed = result.processed ?? 0;
            const total     = result.total     ?? 0;
            const pct       = total > 0 ? Math.round((processed / total) * 100) : (result.done ? 100 : 0);
            renderEnrichmentReport(result.enrichment_report);

            if (bar)  bar.style.width  = `${pct}%`;
            if (text) text.textContent = `Geolocalizzazione: ${processed}/${total} marker (${pct}%)`;
            importLog('info', `Chunk ${calls}: ${processed}/${total} marker`);

            if (result.done || result.status === 'completed') {
                if (text) text.textContent = `Geolocalizzazione completata: ${processed}/${total} marker.`;
                if (bar)  bar.style.width  = '100%';
                setImportPhase('Completato', 100, `Completato: ${processed}/${total} marker`);
                importLog('info', 'Geolocalizzazione completata.');
                try { await loadProperties(); } catch { /* ignore */ }
                if (container) setTimeout(() => { container.style.display = 'none'; }, 4000);
                return;
            }

            if (result.status === 'failed') {
                if (text) {
                    text.textContent = 'Geolocalizzazione non riuscita. Verifica la configurazione GML / Zornade / WFS nel file .env.';
                    text.classList.add('text-danger');
                }
                importLog('error', 'Geolocalizzazione fallita in modalità chunk.');
                if (bar) bar.classList.replace('bg-primary', 'bg-danger');
                return;
            }

            // Breve pausa tra chunk per non saturare il server
            await new Promise(resolve => setTimeout(resolve, 200));
        }

        if (text) text.textContent = 'Geolocalizzazione parziale: limite chiamate raggiunto. Usa "Rigenera coordinate mancanti" per continuare.';
        importLog('warning', 'Limite chunk raggiunto prima del completamento.');
    }

    function renderEnrichmentReport(report) {
        const el = document.getElementById('enrichment-report');
        if (!el || !report || typeof report !== 'object') {
            if (el) {
                el.className = 'small mt-2 d-none';
                el.innerHTML = '';
            }
            return;
        }

        const sourceEntries = Object.entries(report.coord_source || {}).filter(([, value]) => Number(value) > 0);
        const failureEntries = Object.entries(report.failure_codes || {}).filter(([, value]) => Number(value) > 0);
        const unresolved = Array.isArray(report.unresolved_rows) ? report.unresolved_rows : [];

        if (!sourceEntries.length && !failureEntries.length && !unresolved.length) {
            el.className = 'small mt-2 d-none';
            el.innerHTML = '';
            return;
        }

        const html = [];
        if (sourceEntries.length) {
            html.push(`<div><strong>Sorgenti:</strong> ${sourceEntries.map(([key, value]) => `${escapeHtml(key)}=${escapeHtml(String(value))}`).join(' · ')}</div>`);
        }
        if (failureEntries.length) {
            html.push(`<div class="mt-1"><strong>Fallimenti:</strong> ${failureEntries.map(([key, value]) => `${escapeHtml(key)}=${escapeHtml(String(value))}`).join(' · ')}</div>`);
        }
        if (unresolved.length) {
            html.push(`<ul class="mb-0 mt-2 ps-3">${unresolved.map(item => `<li>${escapeHtml(String(item))}</li>`).join('')}${report.truncated ? '<li>… elenco troncato …</li>' : ''}</ul>`);
        }

        el.className = 'small';
        el.innerHTML = html.join('');
    }

    function ensureSharedModals() {
        if (!document.getElementById('property-editor-modal')) {
            const editorModal = document.createElement('div');
            editorModal.innerHTML = `
                <div class="modal fade" id="property-editor-modal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Modifica marker</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                            </div>
                            <div class="modal-body">
                                <div id="property-editor-meta" class="small text-muted mb-3"></div>
                                <div id="property-editor-error" class="alert alert-danger py-2 px-3 small d-none mb-3"></div>
                                <div class="row g-2">
                                    <div class="col-md-6">
                                        <label class="form-label small mb-1">Stato</label>
                                        <select id="editor-state" class="form-select form-select-sm"></select>
                                    </div>
                                    <div class="col-md-6">
                                        <label class="form-label small mb-1">Colore marker</label>
                                        <input type="color" id="editor-color" class="form-control form-control-color form-control-sm" value="#0d6efd">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label small mb-1">Stato personalizzato</label>
                                        <input id="editor-custom-state" class="form-control form-control-sm" placeholder="Stato personalizzato">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label small mb-1">Assegnazioni</label>
                                        <div id="editor-assignments-summary" class="small"></div>
                                    </div>
                                    <div class="col-12">
                                        <button type="button" class="btn btn-outline-secondary btn-sm d-none" id="editor-assignments-open">
                                            <i class="bi bi-person-plus me-1"></i>Gestisci assegnazioni
                                        </button>
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label small mb-1">Nota</label>
                                        <textarea id="editor-note" class="form-control form-control-sm" rows="3" placeholder="Aggiungi nota"></textarea>
                                    </div>
                                </div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Annulla</button>
                                <button type="button" class="btn btn-primary btn-sm" id="editor-save-btn">Salva</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(editorModal.firstElementChild);
        }

        if (!document.getElementById('assignment-picker-modal')) {
            const assignmentModal = document.createElement('div');
            assignmentModal.innerHTML = `
                <div class="modal fade" id="assignment-picker-modal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Assegna subutenti</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                            </div>
                            <div class="modal-body">
                                <div id="assignment-picker-meta" class="small text-muted mb-2"></div>
                                <div id="assignment-picker-error" class="alert alert-danger py-2 px-3 small d-none mb-2"></div>
                                <div id="assignment-picker-list" class="vstack gap-2"></div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Annulla</button>
                                <button type="button" class="btn btn-primary btn-sm" id="assignment-picker-save">Salva assegnazioni</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(assignmentModal.firstElementChild);
        }

        if (!document.getElementById('property-detail-modal')) {
            const detailModal = document.createElement('div');
            detailModal.innerHTML = `
                <div class="modal fade" id="property-detail-modal" tabindex="-1" aria-hidden="true">
                    <div class="modal-dialog modal-lg modal-dialog-scrollable">
                        <div class="modal-content">
                            <div class="modal-header">
                                <h5 class="modal-title">Dettaglio marker</h5>
                                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                            </div>
                            <div class="modal-body">
                                <div id="property-detail-content" class="property-detail-wrapper"></div>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Chiudi</button>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            document.body.appendChild(detailModal.firstElementChild);
        }
    }

    function setModalError(containerId, message) {
        const errorEl = document.getElementById(containerId);
        if (!errorEl) return;
        if (!message) {
            errorEl.classList.add('d-none');
            errorEl.textContent = '';
            return;
        }
        errorEl.textContent = message;
        errorEl.classList.remove('d-none');
    }

    function refreshEditorAssignmentSummary(property) {
        const summary = document.getElementById('editor-assignments-summary');
        if (!summary) return;
        summary.innerHTML = buildAssignmentSummary(property);
    }

    function openEditorModal(propertyId) {
        ensureSharedModals();
        const property = findPropertyById(propertyId);
        if (!property) {
            alert('Immobile non trovato.');
            return;
        }
        if (!property.can_edit) {
            alert('Non hai i permessi per modificare questo marker.');
            return;
        }
        const modalEl = document.getElementById('property-editor-modal');
        const meta = document.getElementById('property-editor-meta');
        const stateEl = document.getElementById('editor-state');
        const colorEl = document.getElementById('editor-color');
        const customStateEl = document.getElementById('editor-custom-state');
        const noteEl = document.getElementById('editor-note');
        const saveBtn = document.getElementById('editor-save-btn');
        const assignmentBtn = document.getElementById('editor-assignments-open');
        if (!modalEl || !stateEl || !colorEl || !customStateEl || !noteEl || !saveBtn) return;

        setModalError('property-editor-error', '');
        meta.textContent = `${property.comune || ''} · ${unitLabel(property)} · ${`${property.indirizzo || ''} ${property.civico || ''}`.trim()}`;
        stateEl.innerHTML = buildSelectOptions(property.stato ?? '');
        colorEl.value = property.colore_marker || '#0d6efd';
        customStateEl.value = property.stato_personalizzato || '';
        noteEl.value = '';
        saveBtn.dataset.propertyId = String(property.id);
        refreshEditorAssignmentSummary(property);
        if (assignmentBtn) {
            assignmentBtn.classList.toggle('d-none', state.role === 'subuser');
            assignmentBtn.dataset.propertyId = String(property.id);
        }
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    function openDetailModal(propertyId) {
        ensureSharedModals();
        const property = findPropertyById(propertyId);
        const modalEl = document.getElementById('property-detail-modal');
        const bodyEl = document.getElementById('property-detail-content');
        if (!property || !modalEl || !bodyEl) return;
        bodyEl.innerHTML = buildPropertyCardHtml(property, { mapMode: false });
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    function openAssignmentPicker(propertyId) {
        ensureSharedModals();
        const property = findPropertyById(propertyId);
        const modalEl = document.getElementById('assignment-picker-modal');
        const listEl = document.getElementById('assignment-picker-list');
        const metaEl = document.getElementById('assignment-picker-meta');
        const saveBtn = document.getElementById('assignment-picker-save');
        if (!property || !modalEl || !listEl || !metaEl || !saveBtn) return;
        if (state.role === 'subuser') return;

        setModalError('assignment-picker-error', '');
        const selected = new Set((property.assignments || []).map(item => Number(item.subuser_id)));
        metaEl.textContent = `${property.comune || ''} · ${unitLabel(property)}`;
        listEl.innerHTML = state.subusers.length
            ? state.subusers.map(subuser => `
                <label class="form-check">
                    <input class="form-check-input assignment-picker-check" type="checkbox" value="${subuser.id}" ${selected.has(Number(subuser.id)) ? 'checked' : ''}>
                    <span class="form-check-label">${escapeHtml(`${subuser.nome} ${subuser.cognome}`)}</span>
                </label>
            `).join('')
            : '<div class="text-muted small">Nessun subutente disponibile.</div>';
        saveBtn.dataset.propertyId = String(property.id);
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }
    // ---- ADE log modal ----
    const adeLogModal = (() => {
        const modalEl = document.getElementById('ade-log-modal');
        if (!modalEl) return null;
        const bsModal = new bootstrap.Modal(modalEl);
        const bodyEl = document.getElementById('ade-log-modal-body');
        const statusEl = document.getElementById('ade-log-modal-status');
        const footerEl = document.getElementById('ade-log-modal-footer');
        const titleEl = document.getElementById('ade-log-modal-label');
        let currentJobId = null;
        let lastLogId = 0;
        let pollingTimer = null;
        let userScrolled = false;

        const STATUS_COLORS = { queued: 'secondary', extracting: 'info', importing: 'primary', verifying: 'warning', completed: 'success', failed: 'danger' };
        const LOG_COLORS = { error: 'text-danger', warning: 'text-warning', info: 'text-light' };

        function formatSize(bytes) {
            if (bytes >= 1048576) return (bytes / 1048576).toFixed(1) + ' MB';
            if (bytes >= 1024) return (bytes / 1024).toFixed(1) + ' KB';
            return bytes + ' B';
        }

        function appendLogs(logs) {
            if (!bodyEl || !logs.length) return;
            const wasAtBottom = bodyEl.scrollHeight - bodyEl.scrollTop <= bodyEl.clientHeight + 40;
            logs.forEach(log => {
                const div = document.createElement('div');
                div.className = LOG_COLORS[log.level] || 'text-light';
                div.textContent = `[${log.created_at}] ${(log.level || 'info').toUpperCase().padEnd(7)} ${log.message}`;
                bodyEl.appendChild(div);
                if (log.id) lastLogId = Math.max(lastLogId, Number(log.id));
            });
            if (!userScrolled || wasAtBottom) bodyEl.scrollTop = bodyEl.scrollHeight;
        }

        function updateStatus(job) {
            if (!statusEl) return;
            const color = STATUS_COLORS[job.status] || 'secondary';
            statusEl.innerHTML = `<span class="badge bg-${escapeHtml(color)} me-2">${escapeHtml(job.status)}</span>`
                + formatAdeJobProgress(job);
        }

        async function poll() {
            if (!currentJobId) return;
            try {
                const url = `${state.adeJobsEndpoint}?job_id=${currentJobId}&after_id=${lastLogId}`;
                const payload = await api(url);
                const job = payload.job;
                if (job) updateStatus(job);
                appendLogs(payload.logs || []);
                const done = job && (job.status === 'completed' || job.status === 'failed');
                if (done) {
                    stopPolling();
                    if (footerEl) {
                        footerEl.textContent = job.status === 'completed'
                            ? 'Job completato.'
                            : `Job fallito: ${job.error_message || 'errore sconosciuto'}`;
                    }
                } else {
                    pollingTimer = setTimeout(poll, 1500);
                }
            } catch {
                pollingTimer = setTimeout(poll, 3000);
            }
        }

        function stopPolling() {
            if (pollingTimer) { clearTimeout(pollingTimer); pollingTimer = null; }
        }

        if (bodyEl) {
            bodyEl.addEventListener('scroll', () => {
                userScrolled = bodyEl.scrollHeight - bodyEl.scrollTop > bodyEl.clientHeight + 60;
            });
        }

        modalEl.addEventListener('hidden.bs.modal', stopPolling);

        return {
            open(jobId, label) {
                currentJobId = jobId;
                lastLogId = 0;
                userScrolled = false;
                if (bodyEl) bodyEl.innerHTML = '';
                if (titleEl) titleEl.textContent = label || `Job #${jobId}`;
                if (statusEl) statusEl.innerHTML = '';
                if (footerEl) footerEl.textContent = 'Connessione in corso…';
                bsModal.show();
                poll();
            },
            formatSize,
        };
    })();

    function isAdeSqlJob(job) {
        return String(job?.zip_filename || '').toLowerCase().endsWith('.sql');
    }

    function formatAdeJobProgress(job) {
        if (isAdeSqlJob(job)) {
            return `INSERT comuni ${escapeHtml(String(job.processed_comuni))}/${escapeHtml(String(job.total_comuni))} · `
                + `INSERT particelle ${escapeHtml(String(job.processed_particelle))}/${escapeHtml(String(job.total_particelle))}`;
        }

        return `Comuni ${escapeHtml(String(job.processed_comuni))}/${escapeHtml(String(job.total_comuni))} · `
            + `Particelle ${escapeHtml(String(job.processed_particelle))}/${escapeHtml(String(job.total_particelle))}`;
    }

    function setAdeUploadButtonState(inputId, buttonId, idleHtml, loadingHtml, isUploading = false, hasFilesOverride = null) {
        const input = document.getElementById(inputId);
        const button = document.getElementById(buttonId);
        if (!input || !button) return;
        const hasFiles = typeof hasFilesOverride === 'boolean' ? hasFilesOverride : Boolean(input.files?.length);
        button.disabled = isUploading || !hasFiles;
        button.innerHTML = isUploading
            ? loadingHtml
            : idleHtml;
    }

    async function submitAdeUpload(inputId, buttonId, importType, idleHtml, loadingHtml) {
        const input = document.getElementById(inputId);
        if (!input?.files?.length) return;
        const formData = new FormData();
        formData.append('csrf_token', state.csrfToken);
        formData.append('import_type', importType);
        Array.from(input.files).forEach(file => formData.append('files[]', file));

        setAdeUploadButtonState(inputId, buttonId, idleHtml, loadingHtml, true);
        try {
            const response = await fetch(withTenant(state.adeJobsEndpoint), { method: 'POST', body: formData });
            let payload = null;
            try { payload = await response.json(); } catch {}
            if (!response.ok || !payload?.ok) {
                throw new Error(payload?.error || `Upload fallito (${response.status})`);
            }
            input.value = '';
            setAdeUploadButtonState(inputId, buttonId, idleHtml, loadingHtml, false, false);
            const latestJobId = payload.job_ids?.length ? payload.job_ids[payload.job_ids.length - 1] : null;
            await refreshAdeJobs();
            if (latestJobId && adeLogModal) {
                adeLogModal.open(latestJobId, `Job #${latestJobId}`);
            }
        } catch (error) {
            setAdeUploadButtonState(inputId, buttonId, idleHtml, loadingHtml, false);
            throw error;
        }
    }

    async function refreshAdeJobs() {
        const container = document.getElementById('ade-jobs');
        if (!container) return;
        const payload = await api(state.adeJobsEndpoint);
        const jobs = payload.jobs || [];
        container.innerHTML = jobs.map(job => {
            const percent = Number(job.total_particelle) > 0
                ? Math.round((Number(job.processed_particelle) / Number(job.total_particelle)) * 100) : 0;
            return `
                <div class="border rounded p-3 mb-2">
                    <div class="d-flex justify-content-between flex-wrap gap-2">
                        <strong>${escapeHtml(job.provincia_sigla)} · ${escapeHtml(job.zip_filename)}</strong>
                        <span class="badge text-bg-secondary">${escapeHtml(job.status)}</span>
                    </div>
                    <div class="progress my-2" style="height:6px;"><div class="progress-bar" style="width:${percent}%"></div></div>
                    <div class="small text-muted">${formatAdeJobProgress(job)}</div>
                </div>`;
        }).join('') || '<p class="text-muted mb-0">Nessun job ADE presente.</p>';
    }

    // ---- Manual upload (file già sul server) ----
    async function loadAdeServerFiles(options = {}) {
        const {
            type = 'zip',
            listId = 'ade-server-files-list',
            selectAllId = 'ade-server-select-all',
            submitId = 'ade-server-submit',
            emptyLabel = 'Nessun file presente in <code>storage/manual_upload/</code>.',
        } = options;
        const listEl = document.getElementById(listId);
        const selectAllBtn = document.getElementById(selectAllId);
        const submitBtn = document.getElementById(submitId);
        if (!listEl) return;
        if (!state.adeManualFilesEndpoint) {
            listEl.innerHTML = '<p class="text-danger small mb-0">Endpoint lista file non configurato.</p>';
            selectAllBtn && (selectAllBtn.style.display = 'none');
            submitBtn && (submitBtn.style.display = 'none');
            return;
        }

        listEl.innerHTML = '<div class="text-muted small">Caricamento…</div>';

        try {
            const payload = await api(`${state.adeManualFilesEndpoint}?type=${encodeURIComponent(type)}`);
            const files = payload.files || [];

            if (!files.length) {
                listEl.innerHTML = `<p class="text-muted small mb-0">${emptyLabel}</p>`;
                selectAllBtn && (selectAllBtn.style.display = 'none');
                submitBtn && (submitBtn.style.display = 'none');
                return;
            }

            listEl.innerHTML = `<div class="list-group list-group-flush border rounded mb-2">
                ${files.map(f => `
                <label class="list-group-item list-group-item-action py-2 px-3 d-flex align-items-center gap-2">
                    <input type="checkbox" class="form-check-input ade-server-file-check" value="${escapeHtml(f.name)}">
                    <span class="flex-grow-1 text-truncate font-monospace small">${escapeHtml(f.name)}</span>
                    <span class="text-muted small text-nowrap">${escapeHtml(adeLogModal?.formatSize(f.size) || String(f.size))}</span>
                </label>`).join('')}
            </div>`;

            if (selectAllBtn) { selectAllBtn.style.removeProperty('display'); }
            if (submitBtn) { submitBtn.style.removeProperty('display'); submitBtn.disabled = true; }

            listEl.querySelectorAll('.ade-server-file-check').forEach(cb => {
                cb.addEventListener('change', () => {
                    const anyChecked = !!listEl.querySelector('.ade-server-file-check:checked');
                    if (submitBtn) submitBtn.disabled = !anyChecked;
                });
            });
        } catch (error) {
            listEl.innerHTML = `<p class="text-danger small mb-0">Errore: ${escapeHtml(error.message)}</p>`;
        }
    }

    async function submitAdeServerFiles(options = {}) {
        const {
            type = 'zip',
            listId = 'ade-server-files-list',
            submitId = 'ade-server-submit',
            idleHtml = '<i class="bi bi-play-fill me-1"></i>Importa selezionati',
            loadingHtml = '<span class="spinner-border spinner-border-sm me-1"></span>Elaborazione…',
            reloadOptions = options,
        } = options;
        const listEl = document.getElementById(listId);
        const submitBtn = document.getElementById(submitId);
        if (!listEl || !state.adeManualFilesEndpoint) return;

        const checked = Array.from(listEl.querySelectorAll('.ade-server-file-check:checked')).map(cb => cb.value);
        if (!checked.length) return;

        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = loadingHtml;
        }

        try {
            const formData = new FormData();
            formData.append('csrf_token', state.csrfToken);
            formData.append('type', type);
            checked.forEach(name => formData.append('files[]', name));

            const response = await fetch(state.adeManualFilesEndpoint, { method: 'POST', body: formData });
            let payload = null;
            try { payload = await response.json(); } catch {}
            if (!response.ok || !payload?.ok) {
                throw new Error(payload?.error || `Errore (${response.status})`);
            }

            await loadAdeServerFiles(reloadOptions);
            await refreshAdeJobs();

            const latestJobId = payload.job_ids?.length ? payload.job_ids[payload.job_ids.length - 1] : null;
            if (latestJobId && adeLogModal) {
                adeLogModal.open(latestJobId, `Job #${latestJobId}`);
            }
        } catch (error) {
            alert(error.message);
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = idleHtml;
            }
        }
    }

    document.addEventListener('change', event => {
        if (event.target.id === 'import-files' && event.target.files?.length) {
            runImport(event.target.files).catch(error => {
                state.overlay?.hide();
                alert(error.message);
            });
        }
        if (event.target.classList.contains('state-select')) {
            const wrapper = event.target.closest('[data-property-id]') || event.target.closest('tr');
            const colorInput = wrapper?.querySelector('.color-input');
            if (colorInput) colorInput.value = defaultColorForState(event.target.value);
        }
        if (event.target.id === 'assigned-subuser-filter') {
            const subuserId = event.target.value;
            api(withTenant(`${state.propertiesEndpoint}?mode=assigned${subuserId ? `&subuser_id=${subuserId}` : ''}`)).then(payload => {
                state.assignedProperties = payload.properties || [];
                renderAssignedTable();
            }).catch(error => alert(error.message));
        }
        if (event.target.id === 'assigned-assignment-filter') {
            renderAssignedTable();
        }
        if (event.target.id === 'report-filter-color' || event.target.id === 'report-filter-stato') {
            applyReportFilters();
        }
        if (event.target.id === 'ade-zips') {
            setAdeUploadButtonState('ade-zips', 'ade-zips-submit', '<i class="bi bi-cloud-upload me-1"></i>Importa', '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Caricamento...', false);
        }
        if (event.target.id === 'ade-sql-files') {
            setAdeUploadButtonState('ade-sql-files', 'ade-sql-submit', '<i class="bi bi-cloud-upload me-1"></i>Importa SQL', '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Caricamento...', false);
        }
    });

    document.addEventListener('click', event => {
        const closeMapPopupBtn = event.target.closest('.close-map-popup');
        if (closeMapPopupBtn) {
            event.preventDefault();
            state.map?.closePopup();
            return;
        }
        const closeDetailBtn = event.target.closest('.close-detail-modal');
        if (closeDetailBtn) {
            event.preventDefault();
            bootstrap.Modal.getInstance(document.getElementById('property-detail-modal'))?.hide();
            return;
        }
        const detailBtn = event.target.closest('.open-detail-modal');
        if (detailBtn) {
            event.preventDefault();
            openDetailModal(Number(detailBtn.dataset.propertyId || 0));
            return;
        }
        const editorBtn = event.target.closest('.open-editor-modal');
        if (editorBtn) {
            event.preventDefault();
            openEditorModal(Number(editorBtn.dataset.propertyId || 0));
            return;
        }
        const assignmentBtn = event.target.closest('.assignment-picker-btn');
        if (assignmentBtn) {
            event.preventDefault();
            openAssignmentPicker(Number(assignmentBtn.dataset.propertyId || 0));
            return;
        }
        if (event.target.id === 'editor-assignments-open') {
            event.preventDefault();
            openAssignmentPicker(Number(event.target.dataset.propertyId || 0));
            return;
        }
        if (event.target.id === 'assignment-picker-save') {
            event.preventDefault();
            const propertyId = Number(event.target.dataset.propertyId || 0);
            const property = findPropertyById(propertyId);
            if (!property) return;
            const checks = Array.from(document.querySelectorAll('#assignment-picker-list .assignment-picker-check:checked'));
            const assignments = checks.map(check => Number(check.value)).filter(Number.isFinite);
            event.target.disabled = true;
            savePropertyPayload({
                property_id: propertyId,
                stato: property.stato,
                stato_personalizzato: property.stato_personalizzato || '',
                colore_marker: property.colore_marker,
                note: '',
                assignments,
            }).then(() => {
                bootstrap.Modal.getInstance(document.getElementById('assignment-picker-modal'))?.hide();
                const updated = findPropertyById(propertyId);
                if (updated) {
                    refreshEditorAssignmentSummary(updated);
                }
            }).catch(error => setModalError('assignment-picker-error', error.message)).finally(() => {
                event.target.disabled = false;
            });
            return;
        }
        if (event.target.id === 'editor-save-btn') {
            event.preventDefault();
            const propertyId = Number(event.target.dataset.propertyId || 0);
            const property = findPropertyById(propertyId);
            if (!property) return;
            const stateEl = document.getElementById('editor-state');
            const colorEl = document.getElementById('editor-color');
            const customStateEl = document.getElementById('editor-custom-state');
            const noteEl = document.getElementById('editor-note');
            event.target.disabled = true;
            setModalError('property-editor-error', '');
            savePropertyPayload({
                property_id: propertyId,
                stato: stateEl?.value || property.stato,
                stato_personalizzato: customStateEl?.value || '',
                colore_marker: colorEl?.value || property.colore_marker,
                note: noteEl?.value || '',
            }).then(() => {
                bootstrap.Modal.getInstance(document.getElementById('property-editor-modal'))?.hide();
            }).catch(error => setModalError('property-editor-error', error.message)).finally(() => {
                event.target.disabled = false;
            });
            return;
        }
        const saveButton = event.target.closest('.property-save');
        if (saveButton) {
            event.preventDefault();
            saveProperty(saveButton);
        }
        if (event.target.id === 'refresh-map') {
            loadProperties().catch(error => alert(error.message));
        }
        if (event.target.id === 'btn-apply-filter') {
            const checkboxes = document.querySelectorAll('.map-stato-filter:checked');
            state.mapStatiFilter = Array.from(checkboxes).map(cb => cb.value);
            renderMap();
        }
        if (event.target.id === 'btn-select-all-stati') {
            const checkboxes = document.querySelectorAll('.map-stato-filter');
            const allChecked = Array.from(checkboxes).every(cb => cb.checked);
            checkboxes.forEach(cb => { cb.checked = !allChecked; });
        }
        if (event.target.id === 'ade-zips-submit') {
            submitAdeUpload('ade-zips', 'ade-zips-submit', 'zip', '<i class="bi bi-cloud-upload me-1"></i>Importa', '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Caricamento...').catch(error => alert(error.message));
        }
        if (event.target.id === 'ade-sql-submit') {
            submitAdeUpload('ade-sql-files', 'ade-sql-submit', 'sql', '<i class="bi bi-cloud-upload me-1"></i>Importa SQL', '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Caricamento...').catch(error => alert(error.message));
        }
        if (event.target.id === 'ade-server-submit') {
            submitAdeServerFiles({
                type: 'zip',
                listId: 'ade-server-files-list',
                submitId: 'ade-server-submit',
                idleHtml: '<i class="bi bi-play-fill me-1"></i>Importa selezionati',
                loadingHtml: '<span class="spinner-border spinner-border-sm me-1"></span>Elaborazione…',
                reloadOptions: {
                    type: 'zip',
                    listId: 'ade-server-files-list',
                    selectAllId: 'ade-server-select-all',
                    submitId: 'ade-server-submit',
                    emptyLabel: 'Nessun file ZIP presente in <code>storage/manual_upload/</code>.',
                },
            }).catch(error => alert(error.message));
        }
        if (event.target.id === 'ade-server-sql-submit') {
            submitAdeServerFiles({
                type: 'sql',
                listId: 'ade-server-sql-files-list',
                submitId: 'ade-server-sql-submit',
                idleHtml: '<i class="bi bi-play-fill me-1"></i>Importa SQL selezionati',
                loadingHtml: '<span class="spinner-border spinner-border-sm me-1"></span>Elaborazione…',
                reloadOptions: {
                    type: 'sql',
                    listId: 'ade-server-sql-files-list',
                    selectAllId: 'ade-server-sql-select-all',
                    submitId: 'ade-server-sql-submit',
                    emptyLabel: 'Nessun file SQL presente in <code>storage/manual_upload/</code>.',
                },
            }).catch(error => alert(error.message));
        }
        if (event.target.id === 'ade-server-select-all') {
            const listEl = document.getElementById('ade-server-files-list');
            const submitBtn = document.getElementById('ade-server-submit');
            const allChecks = listEl?.querySelectorAll('.ade-server-file-check') || [];
            const allChecked = Array.from(allChecks).every(cb => cb.checked);
            allChecks.forEach(cb => { cb.checked = !allChecked; });
            if (submitBtn) submitBtn.disabled = allChecked;
        }
        if (event.target.id === 'ade-server-sql-select-all') {
            const listEl = document.getElementById('ade-server-sql-files-list');
            const submitBtn = document.getElementById('ade-server-sql-submit');
            const allChecks = listEl?.querySelectorAll('.ade-server-file-check') || [];
            const allChecked = Array.from(allChecks).every(cb => cb.checked);
            allChecks.forEach(cb => { cb.checked = !allChecked; });
            if (submitBtn) submitBtn.disabled = allChecked;
        }
        const logBtn = event.target.closest('.ade-open-log-btn');
        if (logBtn && adeLogModal) {
            const jobId = logBtn.dataset.jobId;
            const label = logBtn.dataset.jobLabel || `Job #${jobId}`;
            adeLogModal.open(Number(jobId), label);
        }
    });

    document.getElementById('assigned-save')?.addEventListener('click', async () => {
        await loadProperties();
    });
    ['report-filter-comune', 'report-filter-foglio', 'report-filter-assigned'].forEach(id => {
        document.getElementById(id)?.addEventListener('input', () => applyReportFilters());
    });

    // ----- Drop-zone drag & drop (importa.php) -----
    (function () {
        const zone = document.getElementById('import-drop-zone');
        if (!zone) return;
        zone.addEventListener('dragover', event => { event.preventDefault(); zone.classList.add('dragover'); });
        zone.addEventListener('dragleave', () => zone.classList.remove('dragover'));
        zone.addEventListener('drop', event => {
            event.preventDefault();
            zone.classList.remove('dragover');
            const validExts = ['.csv', '.xlsx', '.xls'];
            const files = Array.from(event.dataTransfer.files).filter(file =>
                validExts.some(ext => file.name.toLowerCase().endsWith(ext))
            );
            if (!files.length) {
                alert('Nessun file valido trovato. Formati accettati: .csv, .xlsx, .xls');
                return;
            }
            runImport(files).catch(error => {
                state.overlay?.hide();
                alert(error.message);
            });
        });
        zone.addEventListener('click', event => {
            if (!event.target.closest('label')) {
                document.getElementById('import-files')?.click();
            }
        });
        zone.addEventListener('keydown', event => {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                document.getElementById('import-files')?.click();
            }
        });
    })();

    ensureSharedModals();

    if (state.propertiesEndpoint) {
        loadProperties().catch(error => alert(error.message));
    }
    if (document.getElementById('ade-jobs')) {
        if (!state.adeManualFilesEndpoint && state.adeJobsEndpoint) {
            try {
                const jobsUrl = new URL(state.adeJobsEndpoint, window.location.origin);
                const replacedPathname = jobsUrl.pathname.replace(/ade_jobs\.php$/, 'ade_manual_files.php');
                if (replacedPathname !== jobsUrl.pathname) {
                    jobsUrl.pathname = replacedPathname;
                    jobsUrl.search = '';
                    jobsUrl.hash = '';
                    state.adeManualFilesEndpoint = jobsUrl.toString();
                } else {
                    const fallbackEndpoint = state.adeJobsEndpoint.replace(/ade_jobs\.php(?:\?.*)?(?:#.*)?$/, 'ade_manual_files.php');
                    state.adeManualFilesEndpoint = fallbackEndpoint !== state.adeJobsEndpoint ? fallbackEndpoint : '';
                }
            } catch (_) {
                const fallbackEndpoint = state.adeJobsEndpoint.replace(/ade_jobs\.php(?:\?.*)?(?:#.*)?$/, 'ade_manual_files.php');
                state.adeManualFilesEndpoint = fallbackEndpoint !== state.adeJobsEndpoint ? fallbackEndpoint : '';
            }
        }
        refreshAdeJobs().catch(() => {});
        loadAdeServerFiles({
            type: 'zip',
            listId: 'ade-server-files-list',
            selectAllId: 'ade-server-select-all',
            submitId: 'ade-server-submit',
            emptyLabel: 'Nessun file ZIP presente in <code>storage/manual_upload/</code>.',
        }).catch(() => {});
        // Load server files when the tab is shown
        document.getElementById('tab-server-btn')?.addEventListener('shown.bs.tab', () => {
            loadAdeServerFiles({
                type: 'zip',
                listId: 'ade-server-files-list',
                selectAllId: 'ade-server-select-all',
                submitId: 'ade-server-submit',
                emptyLabel: 'Nessun file ZIP presente in <code>storage/manual_upload/</code>.',
            }).catch(() => {});
        });
        document.getElementById('tab-sql-btn')?.addEventListener('shown.bs.tab', () => {
            loadAdeServerFiles({
                type: 'sql',
                listId: 'ade-server-sql-files-list',
                selectAllId: 'ade-server-sql-select-all',
                submitId: 'ade-server-sql-submit',
                emptyLabel: 'Nessun file SQL presente in <code>storage/manual_upload/</code>.',
            }).catch(() => {});
        });
        const adePollingInterval = setInterval(() => refreshAdeJobs().catch(() => clearInterval(adePollingInterval)), 5000);
    }

    // ----- "Rigenera coordinate mancanti" button (importa.php) -----
    (function () {
        const btn = document.getElementById('rigenera-coordinate-btn');
        if (!btn || !state.enrichChunkEndpoint) return;
        btn.addEventListener('click', async () => {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>In corso...';
            const container = document.getElementById('enrichment-status-container');
            const bar       = document.getElementById('import-progress-bar');
            const text      = document.getElementById('import-progress-text');
            if (container) {
                container.style.display = '';
                if (bar) { bar.style.width = '0%'; bar.className = 'progress-bar bg-primary progress-bar-striped progress-bar-animated'; }
                if (text) { text.textContent = 'Rigenera coordinate in corso...'; text.className = 'small mb-2'; }
            }
            setImportPhase('Geolocalizzazione', 0, 'Rigenera coordinate in corso...');
            importLog('info', 'Rigenera coordinate mancanti avviato (batch globale).');
            // Usa batch_id=0: il server elaborerà tutte le particelle con lat IS NULL
            try {
                await enrichChunkLoop(0);
            } catch { /* ignore */ }
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-geo-alt me-1"></i>Rigenera coordinate mancanti';
        });
    })();
})();
