(function () {
    'use strict';

    const root = document.getElementById('analyticspro-app');
    if (!root) return;

    const state = {
        csrfToken: document.querySelector('meta[name="csrf-token"]')?.content || '',
        role: root.dataset.role,
        tenantId: root.dataset.tenantId || '',
        selectedTenant: root.dataset.selectedTenant || '',
        canImport: root.dataset.canImport === '1',
        canExport: root.dataset.canExport === '1',
        canViewReports: root.dataset.canViewReports === '1',
        canViewAnalytics: root.dataset.canViewAnalytics === '1',
        propertiesEndpoint: root.dataset.propertiesEndpoint,
        propertyUpdateEndpoint: root.dataset.propertyUpdateEndpoint,
        importEndpoint: root.dataset.importEndpoint,
        importProgressEndpoint: root.dataset.importProgressEndpoint,
        adeJobsEndpoint: root.dataset.adeJobsEndpoint,
        properties: [],
        subusers: [],
        map: null,
        markers: null,
        charts: {},
        tables: {},
        overlay: new bootstrap.Modal(document.getElementById('import-overlay')),
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
        window.addEventListener('load', () => state.map?.invalidateSize(), { once: true });
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
        const genders = owners.reduce((acc, owner) => { const key = owner.genere || 'N/D'; acc[key] = (acc[key] || 0) + 1; return acc; }, {});
        const provinces = state.properties.reduce((acc, property) => { const key = property.provincia || 'N/D'; acc[key] = (acc[key] || 0) + 1; return acc; }, {});
        const comuni = state.properties.reduce((acc, property) => { const key = property.comune || 'N/D'; acc[key] = (acc[key] || 0) + 1; return acc; }, {});
        const categories = state.properties.reduce((acc, property) => { const key = property.categoria || 'N/D'; acc[key] = (acc[key] || 0) + 1; return acc; }, {});
        const ownership = state.properties.reduce((acc, property) => { const key = property.titolarita || 'N/D'; acc[key] = (acc[key] || 0) + 1; return acc; }, {});
        const ages = owners.reduce((acc, owner) => { const key = ageGroup(calcAge(owner.data_nascita)); acc[key] = (acc[key] || 0) + 1; return acc; }, {});

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

        state.overlay.show();
        document.getElementById('import-progress-text').textContent = 'Avvio import...';
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
        await pollImport(processPayload.batch_id);
        state.overlay.hide();
        await loadProperties();
        alert('Import completato.');
    }

    async function pollImport(batchId) {
        while (true) {
            const payload = await api(`${state.importProgressEndpoint}?batch_id=${batchId}`);
            const batch = payload.batch;
            const percent = batch.progress_percent || 0;
            document.getElementById('import-progress-bar').style.width = `${percent}%`;
            document.getElementById('import-progress-text').textContent = `${percent}% · ${batch.processed_rows}/${batch.total_rows} righe`;
            if (batch.status === 'completed') return;
            if (batch.status === 'failed') throw new Error(batch.error_message || 'Import fallito');
            await new Promise(resolve => setTimeout(resolve, 1000));
        }
    }

    async function refreshAdeJobs(jobId) {
        const container = document.getElementById('ade-jobs');
        if (!container) return;
        const payload = await api(jobId ? `${state.adeJobsEndpoint}?job_id=${jobId}` : state.adeJobsEndpoint);
        const jobs = payload.jobs || (payload.job ? [payload.job] : []);
        const logsByJob = payload.logs ? { [payload.job.id]: payload.logs } : {};
        container.innerHTML = jobs.map(job => {
            const percent = job.total_particelle ? Math.round((Number(job.processed_particelle) / Number(job.total_particelle || 1)) * 100) : 0;
            const logs = logsByJob[job.id] || [];
            return `
                <div class="border rounded p-3 mb-3">
                    <div class="d-flex justify-content-between flex-wrap gap-2"><strong>${escapeHtml(job.provincia_sigla)} · ${escapeHtml(job.zip_filename)}</strong><span class="badge text-bg-secondary">${escapeHtml(job.status)}</span></div>
                    <div class="progress my-2" style="height: 8px;"><div class="progress-bar" style="width:${percent}%"></div></div>
                    <div class="small text-muted">Comuni ${job.processed_comuni}/${job.total_comuni} · Particelle ${job.processed_particelle}/${job.total_particelle}</div>
                    ${logs.length ? `<pre class="small bg-light p-2 mt-2 mb-0">${escapeHtml(logs.map(log => `[${log.created_at}] ${log.level.toUpperCase()} ${log.message}`).join('\n'))}</pre>` : ''}
                </div>`;
        }).join('') || '<p class="text-muted mb-0">Nessun job ADE presente.</p>';
    }

    document.addEventListener('change', event => {
        if (event.target.id === 'import-files' && event.target.files?.length) {
            runImport(event.target.files).catch(error => {
                state.overlay.hide();
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
        if (event.target.id === 'ade-zips' && event.target.files?.length) {
            const formData = new FormData();
            formData.append('csrf_token', state.csrfToken);
            Array.from(event.target.files).forEach(file => formData.append('files[]', file));
            fetch(withTenant(state.adeJobsEndpoint), { method: 'POST', body: formData })
                .then(response => response.json())
                .then(payload => {
                    if (!payload.ok) throw new Error(payload.error || 'Upload fallito');
                    const latestJob = payload.job_ids?.[payload.job_ids.length - 1];
                    refreshAdeJobs(latestJob).catch(console.error);
                })
                .catch(error => alert(error.message));
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
                state.overlay.hide();
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

    loadProperties().catch(error => alert(error.message));
    if (document.getElementById('ade-jobs')) {
        refreshAdeJobs().catch(() => {});
        setInterval(() => refreshAdeJobs().catch(() => {}), 5000);
    }
})();
