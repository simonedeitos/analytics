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
        canImport: root.dataset.canImport === '1',
        canExport: root.dataset.canExport === '1',
        canViewReports: root.dataset.canViewReports === '1',
        canViewAnalytics: root.dataset.canViewAnalytics === '1',
        propertiesEndpoint: root.dataset.propertiesEndpoint || '',
        propertyUpdateEndpoint: root.dataset.propertyUpdateEndpoint || '',
        importEndpoint: root.dataset.importEndpoint || '',
        importProgressEndpoint: root.dataset.importProgressEndpoint || '',
        adeJobsEndpoint: root.dataset.adeJobsEndpoint || '',
        adeManualFilesEndpoint: root.dataset.adeManualFilesEndpoint || '',
        properties: [],
        subusers: [],
        map: null,
        markers: null,
        charts: {},
        tables: {},
        overlay: importOverlayEl ? new bootstrap.Modal(importOverlayEl) : null,
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
            throw new Error(payload.error || 'Operazione non riuscita');
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
        const phoneCount = state.properties.reduce((sum, property) => sum + (property.owners || []).filter(owner => owner.telefono).length, 0);
        const assignedCount = state.role === 'subuser'
            ? state.assignedProperties.length
            : state.properties.filter(property => (property.assignments || []).length > 0).length;

        const setKpi = (key, value) => {
            const el = document.querySelector(`[data-kpi="${key}"]`);
            if (el) el.textContent = value;
        };
        setKpi('properties', state.properties.length);
        setKpi('owners', ownerCount);
        setKpi('phones', phoneCount);
        setKpi('assigned', assignedCount);
    }

    function buildOwnerSummary(property) {
        return (property.owners || []).map(owner => {
            const icon = owner.tipo === 'azienda' ? 'bi-building' : 'bi-person';
            const fullName = `${owner.cognome || ''} ${owner.nome || ''}`.trim() || 'Intestatario';
            return `<span class="owner-badge"><i class="bi ${icon}"></i>${escapeHtml(fullName)}${owner.telefono ? ` · ${escapeHtml(owner.telefono)}` : ''}</span>`;
        }).join(' ');
    }

    function buildAssignmentSummary(property) {
        return (property.assignments || []).map(assignment => `<span class="assignment-badge">${escapeHtml(assignment.subuser_name)}</span>`).join(' ');
    }

    function buildSelectOptions(selected) {
        return Object.entries(STATE_OPTIONS).map(([value, label]) => `<option value="${value}" ${value === selected ? 'selected' : ''}>${escapeHtml(label)}</option>`).join('');
    }

    function buildAssignmentSelect(property) {
        if (state.role === 'subuser' || !state.subusers.length) return buildAssignmentSummary(property);
        const selected = new Set((property.assignments || []).map(item => Number(item.subuser_id)));
        return `<select class="form-select form-select-sm small-select assignment-select" multiple>${state.subusers.map(subuser => `<option value="${subuser.id}" ${selected.has(Number(subuser.id)) ? 'selected' : ''}>${escapeHtml(`${subuser.nome} ${subuser.cognome}`)}</option>`).join('')}</select>`;
    }

    function editableColumns(property) {
        const disabled = property.can_edit ? '' : 'disabled';
        return `
            <select class="form-select form-select-sm property-inline-control state-select" ${disabled}>${buildSelectOptions(property.stato)}</select>
            <input class="form-control form-control-sm property-inline-control mt-1 custom-state-input" value="${escapeHtml(property.stato_personalizzato || '')}" placeholder="Stato personalizzato" ${disabled}>
            <input type="color" class="form-control form-control-color form-control-sm mt-1 color-input" value="${escapeHtml(property.colore_marker || '#0d6efd')}" ${disabled}>
            <textarea class="form-control form-control-sm mt-1 note-input" rows="2" placeholder="Aggiungi nota" ${disabled}></textarea>
            ${buildAssignmentSelect(property)}
            <button class="btn btn-primary btn-sm mt-2 property-save" data-property-id="${property.id}" ${disabled}>Salva</button>
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
            colore: `<span class="color-dot" style="background:${escapeHtml(property.colore_marker || '#0d6efd')}"></span> ${escapeHtml(property.colore_marker || '')}`,
            owners: buildOwnerSummary(property),
            assignments: buildAssignmentSummary(property),
            editor: editableColumns(property),
        }));
    }

    function initDataTable(selector, rows, canExport) {
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
            { title: 'Assegnazioni', data: 'assignments' },
            { title: 'Modifica', data: 'editor' },
        ];

        const theadHtml = `<tr>${columns.map(column => `<th>${column.title}</th>`).join('')}</tr>`;
        const tfootHtml = `<tr>${columns.map(column => `<th>${column.title === 'Modifica' ? '' : `<input type="text" class="form-control form-control-sm" placeholder="Filtra ${column.title}">`}</th>`).join('')}</tr>`;
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
    }

    function renderAssignedTable() {
        if (!document.getElementById('assigned-table')) return;
        initDataTable('#assigned-table', buildTableData(state.assignedProperties), state.canExport || state.role !== 'subuser');
    }

    function renderReportTable() {
        if (!document.getElementById('report-table')) return;
        initDataTable('#report-table', buildTableData(state.properties), state.role !== 'subuser');
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

            state.markers = L.markerClusterGroup();
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
        const ownerRows = (property.owners || []).map(owner => `
            <tr>
                <td><i class="bi ${owner.tipo === 'azienda' ? 'bi-building' : 'bi-person'} me-1"></i>${escapeHtml(`${owner.cognome || ''} ${owner.nome || ''}`.trim())}</td>
                <td>${escapeHtml(owner.codice_fiscale || '—')}</td>
                <td>${escapeHtml(owner.telefono || '—')}</td>
            </tr>`).join('');
        return `
            <div class="map-popup" data-property-id="${property.id}">
                <div class="fw-semibold mb-2">${escapeHtml(property.comune)} · F.${escapeHtml(property.foglio)} P.${escapeHtml(property.particella)}</div>
                <div class="small text-muted mb-2">${escapeHtml(`${property.indirizzo || ''} ${property.civico || ''}`.trim())} · ${escapeHtml(property.categoria || 'Categoria N/D')}</div>
                <table class="table table-sm map-popup-table"><thead><tr><th>Intestatario</th><th>CF/P.IVA</th><th>Telefono</th></tr></thead><tbody>${ownerRows}</tbody></table>
                ${editableColumns(property)}
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
        const withPhone = owners.filter(owner => owner.telefono).length;
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
        setKpiAnalytics('phone', withPhone);
        setKpiAnalytics('email', withEmail);
        setKpiAnalytics('piva',  withPiva);

        pieChart('chart-contacts', ['Con telefono', 'Con email', 'Senza contatti'], [withPhone, withEmail, Math.max(owners.length - Math.max(withPhone, withEmail), 0)]);
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

    async function saveProperty(button) {
        const wrapper = button.closest('[data-property-id]') || button.closest('tr');
        const propertyId = Number(button.dataset.propertyId || wrapper?.dataset.propertyId || 0);
        if (!propertyId) return;
        const stateSelect = wrapper.querySelector('.state-select');
        const colorInput = wrapper.querySelector('.color-input');
        const noteInput = wrapper.querySelector('.note-input');
        const customStateInput = wrapper.querySelector('.custom-state-input');
        const assignmentSelect = wrapper.querySelector('.assignment-select');
        const assignments = assignmentSelect ? Array.from(assignmentSelect.selectedOptions).map(option => Number(option.value)) : undefined;

        button.disabled = true;
        try {
            await api(state.propertyUpdateEndpoint, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    csrf_token: state.csrfToken,
                    property_id: propertyId,
                    stato: stateSelect?.value,
                    stato_personalizzato: customStateInput?.value || '',
                    colore_marker: colorInput?.value,
                    note: noteInput?.value || '',
                    assignments,
                }),
            });
            await loadProperties();
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
        const savedRows = processPayload.saved_rows ?? processPayload.total_rows ?? rows.length;
        alert(`Import completato: ${savedRows} righe salvate. Geolocalizzazione dei marker in corso in background.`);
        // Phase 2 (coordinate enrichment) runs in the background; poll enrichment status
        // so the user can see real progress, and reload the map silently when done.
        if (processPayload.batch_id) {
            pollEnrichment(processPayload.batch_id).catch(() => {});
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
        if (container) container.style.display = '';

        while (true) {
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

            if (bar)  bar.style.width    = `${pct}%`;
            if (text) text.textContent   = `Geolocalizzazione: ${processed}/${total} marker (${pct}%)`;

            if (status === 'completed') {
                if (text) text.textContent = `Geolocalizzazione completata: ${processed}/${total} marker.`;
                if (bar)  bar.style.width  = '100%';
                // Silently refresh the map so newly geocoded markers appear.
                try { await loadProperties(); } catch { /* ignore */ }
                // Hide status bar after a brief delay.
                if (container) setTimeout(() => { container.style.display = 'none'; }, 4000);
                return;
            }

            if (status === 'failed') {
                if (text) {
                    text.textContent = 'Geolocalizzazione non riuscita. Verifica la configurazione di Zornade/WFS nel file .env.';
                    text.classList.add('text-danger');
                }
                if (bar) bar.classList.replace('bg-primary', 'bg-danger');
                return;
            }

            // Still processing — poll again after 2.5 s.
            await new Promise(resolve => setTimeout(resolve, 2500));
        }
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
        if (event.target.id === 'ade-zips') {
            setAdeUploadButtonState('ade-zips', 'ade-zips-submit', '<i class="bi bi-cloud-upload me-1"></i>Importa', '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Caricamento...', false);
        }
        if (event.target.id === 'ade-sql-files') {
            setAdeUploadButtonState('ade-sql-files', 'ade-sql-submit', '<i class="bi bi-cloud-upload me-1"></i>Importa SQL', '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>Caricamento...', false);
        }
    });

    document.addEventListener('click', event => {
        const saveButton = event.target.closest('.property-save');
        if (saveButton) {
            event.preventDefault();
            saveProperty(saveButton);
        }
        if (event.target.id === 'refresh-map') {
            loadProperties().catch(error => alert(error.message));
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
        const buttons = Array.from(document.querySelectorAll('#assigned-table .property-save:not([disabled])'));
        for (const button of buttons) {
            await saveProperty(button);
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
})();
