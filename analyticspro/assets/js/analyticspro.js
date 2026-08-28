(function () {
    'use strict';

    const root = document.getElementById('analyticspro-app');
    if (!root) return;
    const importOverlayEl = document.getElementById('import-overlay');

    const state = {
        csrfToken: document.querySelector('meta[name="csrf-token"]')?.content || '',
        role: root.dataset.role,
        tenantId: root.dataset.tenantId || '',
        selectedTenant: root.dataset.selectedTenant || '',
        canViewPhone: root.dataset.canViewPhone === '1',
        canImport: root.dataset.canImport === '1',
        canExport: root.dataset.canExport === '1',
        canViewReports: root.dataset.canViewReports === '1',
        canViewAnalytics: root.dataset.canViewAnalytics === '1',
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
        assignedFilter: 'all',
    };

    const STATE_OPTIONS = {
        non_interessato: 'Non Interessato',
        interessato: 'Interessato',
        contattato: 'Contattato',
        da_contattare: 'Da Contattare',
        in_vendita_noi: 'In Vendita NOI',
        in_vendita_altri: 'In Vendita ALTRI',
        altro: 'Altro',
    };
    const isAssignedPage = Boolean(document.getElementById('assigned-table'));

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
        const response = await fetch(url, options);
        if (response.status === 401) {
            const loginUrl = (state.adeJobsEndpoint || state.propertiesEndpoint || '').replace(/\/api\/.*$/, '/login.php') || 'login.php';
            window.location.href = loginUrl;
            return new Promise(() => {});
        }
        const payload = await response.json();
        if (!response.ok || payload.ok === false) {
            const error = new Error(payload.error || 'Operazione non riuscita');
            error.errorCode = payload.error_code || 'request_error';
            error.details = payload.details || null;
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
        const assignedUrl = state.role === 'subuser'
            ? `${state.propertiesEndpoint}?mode=assigned`
            : `${state.propertiesEndpoint}?mode=assigned&assignment_filter=${encodeURIComponent(state.assignedFilter || 'all')}`;
        const [allPayload, assignedPayload] = await Promise.all([
            api(withTenant(`${state.propertiesEndpoint}?mode=all`)),
            api(withTenant(assignedUrl)),
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
        populateAssignedStatusFilter();
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
        }
        setKpi('assigned', assignedCount);
    }

    function buildOwnerSummary(property) {
        return (property.owners || []).map(owner => {
            const icon = owner.tipo === 'azienda' ? 'bi-building' : 'bi-person';
            const fullName = `${owner.cognome || ''} ${owner.nome || ''}`.trim() || 'Intestatario';
            const phone = state.canViewPhone && owner.telefono ? ` · ${escapeHtml(owner.telefono)}` : '';
            return `<span class="owner-badge"><i class="bi ${icon}"></i>${escapeHtml(fullName)}${phone}</span>`;
        }).join(' ');
    }

    function buildAssignmentSummary(property) {
        const names = (property.assignments || []).map(assignment => assignment.subuser_name).filter(Boolean);
        if (!names.length) {
            return '<span class="text-muted">— non assegnato</span>';
        }
        return escapeHtml(names.join(', '));
    }

    function buildSelectOptions(selected) {
        return Object.entries(STATE_OPTIONS).map(([value, label]) => `<option value="${value}" ${value === selected ? 'selected' : ''}>${escapeHtml(label)}</option>`).join('');
    }

    function rowActions(property) {
        const disabled = property.can_edit ? '' : 'disabled';
        const assignIcon = state.role === 'subuser' ? 'bi-people' : 'bi-person-plus';
        return `
            <div class="d-flex gap-1">
                <button class="btn btn-outline-primary btn-sm property-edit" data-property-id="${property.id}" ${disabled}><i class="bi bi-pencil-square"></i></button>
                <button class="btn btn-outline-secondary btn-sm property-assign" data-property-id="${property.id}" ${disabled}><i class="bi ${assignIcon}"></i></button>
            </div>
        `;
    }

    function buildTableData(properties) {
        return properties.map(property => ({
            id: property.id,
            tenant: property.tenant_name || '',
            comune: property.comune || '',
            provincia: property.provincia || '',
            indirizzo: `${property.indirizzo || ''} ${property.civico || ''}`.trim(),
            foglio: property.foglio || '',
            particella: property.particella || '',
            subalterno: property.subalterno || '',
            categoria: property.categoria || '',
            stato: STATE_OPTIONS[property.stato] || property.stato,
            colore: `<span class="color-dot" style="background:${escapeHtml(property.colore_marker || '#0d6efd')}"></span>`,
            owners: buildOwnerSummary(property),
            assigned_to: buildAssignmentSummary(property),
            actions: rowActions(property),
        }));
    }

    function initDataTable(selector, rows, canExport, options = {}) {
        if (state.tables[selector]) {
            state.tables[selector].destroy();
            $(selector).empty().append('<thead></thead><tfoot></tfoot><tbody></tbody>');
        }

        const columns = [
            { title: 'Tenant', data: 'tenant' },
            { title: 'Provincia', data: 'provincia' },
            { title: 'Comune', data: 'comune' },
            { title: 'Indirizzo', data: 'indirizzo' },
            { title: 'Foglio', data: 'foglio' },
            { title: 'Particella', data: 'particella' },
            { title: 'Sub.', data: 'subalterno' },
            { title: 'Categoria', data: 'categoria' },
            { title: 'Stato', data: 'stato' },
            { title: 'Colore', data: 'colore' },
            { title: 'Intestatari', data: 'owners' },
            { title: 'Assegnato a', data: 'assigned_to' },
            { title: 'Azioni', data: 'actions' },
        ];

        const theadHtml = `<tr>${columns.map(column => `<th>${column.title}</th>`).join('')}</tr>`;
        const tfootHtml = `<tr>${columns.map(column => `<th>${column.title === 'Azioni' ? '' : `<input type="text" class="form-control form-control-sm" placeholder="Filtra ${column.title}">`}</th>`).join('')}</tr>`;
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
            columnDefs: [{ targets: [9, 10, 11, 12], orderable: false }],
        });

        state.tables[selector].columns().every(function (index) {
            const input = $(`${selector} tfoot th`).eq(index).find('input');
            if (!input.length) return;
            input.on('keyup change clear', () => {
                if (state.tables[selector].column(index).search() !== input.val()) {
                    state.tables[selector].column(index).search(input.val()).draw();
                }
            });
        });

        if (options.topFilters) {
            const filterContainerId = selector === '#report-table' ? 'report-table-filters' : '';
            if (filterContainerId) {
                const host = document.getElementById(filterContainerId);
                if (host) {
                    host.innerHTML = '';
                    columns.forEach((column, index) => {
                        if (column.title === 'Azioni') return;
                        const wrapper = document.createElement('div');
                        wrapper.className = 'col-12 col-md-6 col-lg-3';
                        wrapper.innerHTML = `<label class="form-label small mb-1">${escapeHtml(column.title)}</label><input type="text" class="form-control form-control-sm" placeholder="Filtra">`;
                        const input = wrapper.querySelector('input');
                        input.addEventListener('input', () => {
                            state.tables[selector].column(index).search(input.value).draw();
                        });
                        host.appendChild(wrapper);
                    });
                }
            }
        }
    }

    function renderAssignedTable() {
        if (!document.getElementById('assigned-table')) return;
        initDataTable('#assigned-table', buildTableData(state.assignedProperties), state.canExport || state.role !== 'subuser');
    }

    function renderReportTable() {
        if (!document.getElementById('report-table')) return;
        initDataTable('#report-table', buildTableData(state.properties), state.role !== 'subuser', { topFilters: true });
    }

    function renderMap() {
        const container = document.getElementById('map-fullpage') || document.getElementById('map-container');
        if (!container) return;
        if (!state.map) {
            state.map = L.map(container).setView([41.9, 12.5], 6);

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
                iconCreateFunction(cluster) {
                    const markers = cluster.getAllChildMarkers();
                    const counts = {};
                    markers.forEach(marker => {
                        const color = marker.options.markerColor || '#0d6efd';
                        counts[color] = (counts[color] || 0) + 1;
                    });
                    const total = markers.length || 1;
                    const radius = total < 10 ? 18 : (total < 50 ? 22 : 26);
                    let start = 0;
                    const segments = Object.entries(counts).map(([color, count]) => {
                        const angle = (count / total) * Math.PI * 2;
                        const end = start + angle;
                        const x1 = 50 + Math.cos(start) * 46;
                        const y1 = 50 + Math.sin(start) * 46;
                        const x2 = 50 + Math.cos(end) * 46;
                        const y2 = 50 + Math.sin(end) * 46;
                        const largeArc = angle > Math.PI ? 1 : 0;
                        const path = `M 50 50 L ${x1.toFixed(2)} ${y1.toFixed(2)} A 46 46 0 ${largeArc} 1 ${x2.toFixed(2)} ${y2.toFixed(2)} Z`;
                        start = end;
                        return `<path d="${path}" fill="${escapeHtml(color)}"></path>`;
                    }).join('');
                    const svg = `<svg viewBox="0 0 100 100" width="${radius * 2}" height="${radius * 2}" aria-hidden="true">${segments}<circle cx="50" cy="50" r="18" fill="rgba(255,255,255,.92)"></circle><text x="50" y="56" text-anchor="middle" font-size="28" font-weight="700" fill="#1f2a37">${total}</text></svg>`;
                    return L.divIcon({
                        html: `<span class="cluster-pie">${svg}</span>`,
                        className: 'ap-cluster-icon',
                        iconSize: L.point(radius * 2, radius * 2),
                    });
                },
            });
            state.map.addLayer(state.markers);
        }

        state.markers.clearLayers();
        const points = state.properties.filter(property => property.lat && property.lng);
        points.forEach(property => {
            const marker = L.circleMarker([Number(property.lat), Number(property.lng)], {
                radius: 8,
                color: property.colore_marker || '#2A519F',
                fillColor: property.colore_marker || '#2A519F',
                fillOpacity: 0.9,
                weight: 2,
                markerColor: property.colore_marker || '#2A519F',
            });
            marker.bindPopup(buildPopupHtml(property), { maxWidth: 420 });
            state.markers.addLayer(marker);
        });
        if (points.length) {
            state.map.fitBounds(state.markers.getBounds().pad(0.2));
        }
        setTimeout(() => state.map.invalidateSize(), 150);
        setTimeout(() => state.map?.invalidateSize(), 600);
    }

    function buildPopupHtml(property) {
        const assignmentText = buildAssignmentSummary(property);
        const assignmentBtn = property.can_edit && state.role !== 'subuser'
            ? `<button class="btn btn-outline-secondary btn-sm property-assign ms-2" data-property-id="${property.id}" title="Assegna subutenti"><i class="bi bi-person-plus"></i></button>`
            : '';
        const ownerCards = (property.owners || []).map(owner => {
            const icon = owner.tipo === 'azienda' ? 'bi-building' : 'bi-person';
            const nomeCompleto = `${owner.cognome || ''} ${owner.nome || ''}`.trim() || 'Intestatario';
            const contactRows = [
                owner.codice_fiscale ? `<div><span class="text-muted">Codice fiscale</span><br><span>${escapeHtml(owner.codice_fiscale)}</span></div>` : '',
                owner.data_nascita ? `<div><span class="text-muted">Data nascita</span><br><span>${escapeHtml(owner.data_nascita)}</span></div>` : '',
                owner.genere ? `<div><span class="text-muted">Genere</span><br><span>${escapeHtml(owner.genere)}</span></div>` : '',
                property.titolarita ? `<div><span class="text-muted">Titolarità</span><br><span>${escapeHtml(property.titolarita)}</span></div>` : '',
                property.quota ? `<div><span class="text-muted">Quota</span><br><span>${escapeHtml(property.quota)}</span></div>` : '',
                owner.indirizzo ? `<div><span class="text-muted">Indirizzo</span><br><span>${escapeHtml(owner.indirizzo)}</span></div>` : '',
                owner.email ? `<div><span class="text-muted">Email</span><br><span>${escapeHtml(owner.email)}</span></div>` : '',
                state.canViewPhone && owner.telefono ? `<div><span class="text-muted">Telefono</span><br><span>${escapeHtml(owner.telefono)}</span></div>` : '',
            ].filter(Boolean).join('');
            return `<div class="border rounded p-2 mb-2">
                <div class="fw-semibold mb-1"><i class="bi ${icon} me-1"></i>${escapeHtml(nomeCompleto)}</div>
                <div class="small d-grid gap-1">${contactRows || '<span class="text-muted">Nessun dettaglio disponibile</span>'}</div>
            </div>`;
        }).join('');
        return `
            <div class="map-popup" data-property-id="${property.id}">
                <div class="fw-semibold mb-2">${escapeHtml(property.comune)} · F.${escapeHtml(property.foglio)} P.${escapeHtml(property.particella)}</div>
                <div class="small text-muted mb-2">${escapeHtml(`${property.indirizzo || ''} ${property.civico || ''}`.trim())} · ${escapeHtml(property.categoria || 'Categoria N/D')}</div>
                <div class="small mb-2"><strong>Assegnati:</strong> ${assignmentText}${assignmentBtn}</div>
                <div class="mb-2"><strong class="small">Intestatario</strong><div class="mt-1">${ownerCards || '<span class="text-muted small">Nessun intestatario</span>'}</div></div>
                ${property.can_edit ? `<button class="btn btn-primary btn-sm property-edit" data-property-id="${property.id}"><i class="bi bi-pencil-square me-1"></i>Modifica marker</button>` : ''}
                <div class="mt-2 small">${(property.notes || []).map(note => `<div class="note-badge"><strong>${escapeHtml(note.author_name_snapshot)}</strong> ${escapeHtml(note.created_at)} · ${escapeHtml(note.testo)}</div>`).join('') || '<span class="text-muted">Nessuna nota</span>'}</div>
            </div>`;
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
        }
        setKpiAnalytics('email', withEmail);
        setKpiAnalytics('piva',  withPiva);

        if (state.canViewPhone) {
            pieChart('chart-contacts', ['Con telefono', 'Con email', 'Senza contatti'], [withPhone, withEmail, Math.max(owners.length - Math.max(withPhone, withEmail), 0)]);
        } else {
            pieChart('chart-contacts', ['Con email', 'Senza email'], [withEmail, Math.max(owners.length - withEmail, 0)]);
        }
        pieChart('chart-gender', Object.keys(genders), Object.values(genders));
        barChart('chart-age', Object.keys(ages), Object.values(ages), 'Intestatari');
        pieChart('chart-province', Object.keys(provinces), Object.values(provinces));
        barChart('chart-comune', Object.keys(comuni).slice(0, 10), Object.values(comuni).slice(0, 10), 'Immobili');
        pieChart('chart-categoria', Object.keys(categories), Object.values(categories));
        pieChart('chart-titolarita', Object.keys(ownership), Object.values(ownership));
    }

    function assignmentCounts() {
        const base = isAssignedPage ? state.assignedProperties : state.properties;
        const total = base.length;
        const assigned = base.filter(property => (property.assignments || []).length > 0).length;
        return { total, assigned, unassigned: Math.max(total - assigned, 0) };
    }

    function populateAssignedSubuserFilter() {
        const select = document.getElementById('assigned-subuser-filter');
        if (!select) return;
        select.innerHTML = `<option value="">${state.role === 'subuser' ? 'Le mie assegnazioni' : 'Tutte le assegnazioni'}</option>` + state.subusers.map(subuser => `<option value="${subuser.id}">${escapeHtml(`${subuser.nome} ${subuser.cognome}`)}</option>`).join('');
    }

    function populateAssignedStatusFilter() {
        const select = document.getElementById('assigned-status-filter');
        if (!select || !isAssignedPage) return;
        const counts = assignmentCounts();
        select.innerHTML = [
            `<option value="all" ${state.assignedFilter === 'all' ? 'selected' : ''}>Tutti (${counts.total})</option>`,
            `<option value="assigned" ${state.assignedFilter === 'assigned' ? 'selected' : ''}>Assegnati (${counts.assigned})</option>`,
            `<option value="unassigned" ${state.assignedFilter === 'unassigned' ? 'selected' : ''}>Non assegnati (${counts.unassigned})</option>`,
        ].join('');
    }

    function ensureEditorModal() {
        if (document.getElementById('property-editor-modal')) return;
        const modalHtml = `
            <div class="modal fade" id="property-editor-modal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Modifica marker</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                        </div>
                        <div class="modal-body">
                            <input type="hidden" id="editor-property-id" value="">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label small">Stato</label>
                                    <select id="editor-state" class="form-select form-select-sm"></select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small">Colore marker</label>
                                    <input type="color" id="editor-color" class="form-control form-control-color">
                                </div>
                                <div class="col-12">
                                    <label class="form-label small">Stato personalizzato</label>
                                    <input id="editor-custom-state" class="form-control form-control-sm" placeholder="Stato personalizzato">
                                </div>
                                <div class="col-12">
                                    <label class="form-label small">Nota</label>
                                    <textarea id="editor-note" rows="2" class="form-control form-control-sm" placeholder="Aggiungi nota"></textarea>
                                </div>
                                <div class="col-12" id="editor-assignment-wrapper">
                                    <label class="form-label small">Assegna subutenti</label>
                                    <div id="editor-assignment-list" class="d-flex flex-wrap gap-2"></div>
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Annulla</button>
                            <button type="button" class="btn btn-primary btn-sm" id="editor-save-btn">Salva</button>
                        </div>
                    </div>
                </div>
            </div>`;
        document.body.insertAdjacentHTML('beforeend', modalHtml);
    }

    function openPropertyEditor(propertyId, assignmentsOnly = false) {
        const property = state.properties.find(item => Number(item.id) === Number(propertyId))
            || state.assignedProperties.find(item => Number(item.id) === Number(propertyId));
        if (!property) return;
        ensureEditorModal();
        const modalEl = document.getElementById('property-editor-modal');
        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        document.getElementById('editor-property-id').value = String(property.id);
        document.getElementById('editor-state').innerHTML = buildSelectOptions(property.stato);
        document.getElementById('editor-color').value = property.colore_marker || '#0d6efd';
        document.getElementById('editor-custom-state').value = property.stato_personalizzato || '';
        document.getElementById('editor-note').value = '';
        const assignmentWrapper = document.getElementById('editor-assignment-wrapper');
        const assignmentList = document.getElementById('editor-assignment-list');
        if (state.role === 'subuser' || !property.can_edit || !state.subusers.length) {
            assignmentWrapper.classList.add('d-none');
        } else {
            assignmentWrapper.classList.remove('d-none');
            const selected = new Set((property.assignments || []).map(item => Number(item.subuser_id)));
            assignmentList.innerHTML = state.subusers.map(subuser => `
                <label class="border rounded px-2 py-1 small">
                    <input type="checkbox" class="form-check-input me-1 editor-assignment-check" value="${subuser.id}" ${selected.has(Number(subuser.id)) ? 'checked' : ''}>
                    ${escapeHtml(`${subuser.nome} ${subuser.cognome}`)}
                </label>
            `).join('');
        }
        modalEl.dataset.assignmentsOnly = assignmentsOnly ? '1' : '0';
        modal.show();
    }

    async function saveProperty(button, payloadOverride = null) {
        const propertyId = Number(payloadOverride?.property_id || button?.dataset?.propertyId || 0);
        if (!propertyId) return;

        if (button) button.disabled = true;
        try {
            await api(state.propertyUpdateEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf_token: state.csrfToken,
                    property_id: propertyId,
                    stato: payloadOverride?.stato,
                    stato_personalizzato: payloadOverride?.stato_personalizzato || '',
                    colore_marker: payloadOverride?.colore_marker,
                    note: payloadOverride?.note || '',
                    assignments: payloadOverride?.assignments,
                }),
            });
            await loadProperties();
        } catch (error) {
            alert(error.message);
            throw error;
        } finally {
            if (button) button.disabled = false;
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

    async function runImport(files) {
        const rows = await parseFiles(files);
        if (!rows.length) {
            alert('Nessuna riga valida trovata nei file selezionati.');
            return;
        }

        const analysis = await api(state.importEndpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ csrf_token: state.csrfToken, mode: 'analyze', rows }),
        });

        const decisions = {};
        for (const conflict of analysis.conflicts || []) {
            const confirmUpdate = window.confirm(`Duplicato per ${conflict.comune} F.${conflict.foglio} P.${conflict.particella}${conflict.subalterno ? `/${conflict.subalterno}` : ''}.\nNuovo intestatario: ${conflict.incoming_owner}.\nVuoi aggiornare il dato con il nuovo intestatario?`);
            decisions[conflict.row_index] = confirmUpdate ? 'updated' : 'kept_old';
        }

        state.overlay?.show();
        const progressText = document.getElementById('import-progress-text');
        if (progressText) progressText.textContent = 'Avvio import...';
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
        // Phase 1 completed synchronously: persistence is done, show result now.
        state.overlay?.hide();
        await loadProperties();
        const progressTextAfterImport = document.getElementById('import-progress-text');
        if (progressTextAfterImport) {
            progressTextAfterImport.textContent = `Import completato: ${processPayload.saved_rows ?? processPayload.total_rows ?? rows.length} righe salvate.`;
        }
        // Phase 2 (coordinate enrichment): if the background worker was launched
        // successfully, poll its status. If not (enrichment_sync = true), drive the
        // enrichment directly via repeated chunk calls so the spinner always terminates.
        if (processPayload.batch_id && state.enrichChunkEndpoint) {
            enrichChunkLoop(0, { scopeBatchId: Number(processPayload.batch_id) || 0 }).catch(() => {});
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
        const bar       = document.getElementById('enrichment-progress-bar');
        const text      = document.getElementById('enrichment-progress-text');
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

            if (bar)  bar.style.width    = `${pct}%`;
            if (text) text.textContent   = `Geolocalizzazione: ${processed}/${total} marker (${pct}%)`;

            if (status === 'completed') {
                if (text) text.textContent = `Geolocalizzazione completata: ${processed}/${total} marker.`;
                if (bar)  bar.style.width  = '100%';
                try { await loadProperties(); } catch { /* ignore */ }
                if (container) setTimeout(() => { container.style.display = 'none'; }, 4000);
                return;
            }

            if (status === 'failed') {
                if (text) {
                    text.textContent = 'Geolocalizzazione non riuscita. Verifica la configurazione GML / Zornade / WFS nel file .env, oppure usa "Rigenera coordinate mancanti" per riprovare.';
                    text.classList.add('text-danger');
                }
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
                    enrichChunkLoop(0, { scopeBatchId: Number(batchId) || 0 }).catch(() => {});
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
    function showEnrichmentRetry(textEl, retryBtn, message, retryHandler) {
        if (textEl) {
            textEl.textContent = message;
            textEl.classList.add('text-danger');
        }
        if (retryBtn) {
            retryBtn.classList.remove('d-none');
            retryBtn.onclick = retryHandler;
        }
    }

    async function enrichChunkLoop(batchId, options = {}) {
        const container = document.getElementById('enrichment-status-container');
        const bar       = document.getElementById('enrichment-progress-bar');
        const text      = document.getElementById('enrichment-progress-text');
        const reportEl  = document.getElementById('enrichment-report');
        const retryBtn  = document.getElementById('enrichment-retry-btn');
        const scopeBatchId = Number(options.scopeBatchId || 0);
        if (container) container.style.display = '';
        if (reportEl) {
            reportEl.className = 'small mt-2 d-none';
            reportEl.innerHTML = '';
        }
        if (retryBtn) retryBtn.classList.add('d-none');

        const maxChunks = 500; // sicurezza — max 500 * 25 = 12 500 particelle
        let calls = 0;
        let consecutiveFailures = 0;

        if (text) text.textContent = 'Geolocalizzazione sincrona in corso...';

        while (calls < maxChunks) {
            calls++;
            let result;
            try {
                const scopedQuery = scopeBatchId > 0 ? `&scope_batch_id=${encodeURIComponent(String(scopeBatchId))}` : '';
                result = await api(`${state.enrichChunkEndpoint}?batch_id=${batchId}&limit=25${scopedQuery}`);
                consecutiveFailures = 0;
            } catch (error) {
                consecutiveFailures++;
                if (consecutiveFailures >= 3) {
                    const serverCode = error?.errorCode ? ` [${error.errorCode}]` : '';
                    const reason = error?.message ? ` ${error.message}` : '';
                    showEnrichmentRetry(
                        text,
                        retryBtn,
                        `Geolocalizzazione interrotta dopo 3 tentativi falliti.${serverCode}${reason}`,
                        () => enrichChunkLoop(batchId, options).catch(() => {})
                    );
                    return;
                }
                await new Promise(resolve => setTimeout(resolve, 1200));
                continue;
            }

            const processed = result.processed ?? 0;
            const total     = result.total     ?? 0;
            const pct       = total > 0 ? Math.round((processed / total) * 100) : (result.done ? 100 : 0);
            renderEnrichmentReport(result.enrichment_report);

            if (bar)  bar.style.width  = `${pct}%`;
            if (text) text.textContent = `Geolocalizzazione: ${processed}/${total} marker (${pct}%)`;

            if (result.done || result.status === 'completed') {
                if (text) text.textContent = `Geolocalizzazione completata: ${processed}/${total} marker.`;
                if (bar)  bar.style.width  = '100%';
                try { await loadProperties(); } catch { /* ignore */ }
                if (container) setTimeout(() => { container.style.display = 'none'; }, 4000);
                return;
            }

            if (result.status === 'failed') {
                const serverCode = result.error_code ? ` [${result.error_code}]` : '';
                const reason = result.error ? ` ${result.error}` : '';
                showEnrichmentRetry(
                    text,
                    retryBtn,
                    `Geolocalizzazione non riuscita.${serverCode}${reason}`,
                    () => enrichChunkLoop(batchId, options).catch(() => {})
                );
                if (bar) bar.classList.replace('bg-primary', 'bg-danger');
                return;
            }

            // Breve pausa tra chunk per non saturare il server
            await new Promise(resolve => setTimeout(resolve, 200));
        }

        showEnrichmentRetry(
            text,
            retryBtn,
            'Geolocalizzazione parziale: limite chiamate raggiunto.',
            () => enrichChunkLoop(batchId, options).catch(() => {})
        );
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

        el.className = 'small mt-2';
        el.innerHTML = html.join('');
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
        if (event.target.id === 'editor-state') {
            const colorInput = document.getElementById('editor-color');
            if (colorInput) colorInput.value = defaultColorForState(event.target.value);
        }
        if (event.target.id === 'assigned-subuser-filter') {
            const subuserId = event.target.value;
            const assignmentFilter = encodeURIComponent(state.assignedFilter || 'all');
            api(withTenant(`${state.propertiesEndpoint}?mode=assigned&assignment_filter=${assignmentFilter}${subuserId ? `&subuser_id=${subuserId}` : ''}`)).then(payload => {
                state.assignedProperties = payload.properties || [];
                renderAssignedTable();
            }).catch(error => alert(error.message));
        }
        if (event.target.id === 'assigned-status-filter') {
            state.assignedFilter = event.target.value || 'all';
            const subuserId = document.getElementById('assigned-subuser-filter')?.value || '';
            api(withTenant(`${state.propertiesEndpoint}?mode=assigned&assignment_filter=${encodeURIComponent(state.assignedFilter)}${subuserId ? `&subuser_id=${subuserId}` : ''}`)).then(payload => {
                state.assignedProperties = payload.properties || [];
                renderAssignedTable();
                populateAssignedStatusFilter();
            }).catch(error => alert(error.message));
        }
        if (event.target.id === 'ade-zips') {
            setAdeUploadButtonState('ade-zips', 'ade-zips-submit', '<i class="bi bi-cloud-upload me-1"></i>Importa', '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Caricamento...', false);
        }
        if (event.target.id === 'ade-sql-files') {
            setAdeUploadButtonState('ade-sql-files', 'ade-sql-submit', '<i class="bi bi-cloud-upload me-1"></i>Importa SQL', '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Caricamento...', false);
        }
    });

    document.addEventListener('click', event => {
        const editButton = event.target.closest('.property-edit');
        if (editButton) {
            event.preventDefault();
            openPropertyEditor(Number(editButton.dataset.propertyId || 0), false);
        }
        const assignButton = event.target.closest('.property-assign');
        if (assignButton) {
            event.preventDefault();
            openPropertyEditor(Number(assignButton.dataset.propertyId || 0), true);
        }
        if (event.target.id === 'refresh-map') {
            loadProperties().catch(error => alert(error.message));
        }
        if (event.target.id === 'editor-save-btn') {
            const propertyId = Number(document.getElementById('editor-property-id')?.value || 0);
            const assignments = Array.from(document.querySelectorAll('.editor-assignment-check:checked')).map(el => Number(el.value));
            const payload = {
                property_id: propertyId,
                stato: document.getElementById('editor-state')?.value || undefined,
                stato_personalizzato: document.getElementById('editor-custom-state')?.value || '',
                colore_marker: document.getElementById('editor-color')?.value || undefined,
                note: document.getElementById('editor-note')?.value || '',
                assignments,
            };
            saveProperty(event.target, payload).then(() => {
                const modalEl = document.getElementById('property-editor-modal');
                if (modalEl) {
                    bootstrap.Modal.getOrCreateInstance(modalEl).hide();
                }
            }).catch(() => {});
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
            const bar       = document.getElementById('enrichment-progress-bar');
            const text      = document.getElementById('enrichment-progress-text');
            if (container) {
                container.style.display = '';
                if (bar) { bar.style.width = '0%'; bar.className = 'progress-bar bg-primary progress-bar-striped progress-bar-animated'; }
                if (text) { text.textContent = 'Rigenera coordinate in corso...'; text.className = 'small mb-0'; }
            }
            // Usa batch_id=0: il server elaborerà tutte le particelle con lat IS NULL
            try {
                await enrichChunkLoop(0);
            } catch { /* ignore */ }
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-geo-alt me-1"></i>Rigenera coordinate mancanti';
        });
    })();
})();
