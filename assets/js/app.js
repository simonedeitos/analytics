/**
 * EasyCatasto Analytics – app.js
 * All data processing is performed client-side.
 * No data is sent to any server.
 */
(function () {
    'use strict';

    /* ================================================================
       STATE
    ================================================================ */
    let allRows = [];       // parsed & enriched rows
    let filteredRows = [];  // rows after user filters
    let dataTable = null;   // DataTables instance
    let charts = {};        // Chart.js instances keyed by id
    const CURRENT_YEAR = new Date().getFullYear();
    const NOMINATIM_USER_AGENT = 'EasyCatasto-Analytics/1.0 (+https://github.com/simonedeitos/analytics)';
    const NOMINATIM_CONTACT_EMAIL = 'simonedeitos@users.noreply.github.com';
    const GEOCODE_RATE_LIMIT_MS = 1100;
    const GEOCODE_RETRY_DELAY_MS = 2000;
    const GEOCODE_MAX_ATTEMPTS = 2;
    const MAP_CLUSTER_RADIUS_PIXELS = 50;
    const MAP_PREPARE_DELAY_MS = 500;
    const WFS_SECONDS_PER_TILE = 0.6;
    const WFS_STREAM_TIMEOUT_MS = 180000;
    const COLUMN_SURNAME = 'Nome';
    const COLUMN_GIVEN_NAME = 'Nome1';
    let mapInstance = null;
    let mapMarkers = null;
    let mapDataReady = false;
    let mapGroupedData = [];
    let geocodedAddresses = {};
    let geocodingInProgress = false;
    let mapHasGeocodeErrors = false;
    let geocodeServiceUnavailable = false;
    let mapStatusHideTimer = null;
    let lastGeocodeRequestAt = 0;
    let particelleCache = {}; // AdE scan cache: key → {lat, lng, foglio, particella, cod_comune}

    /* ================================================================
       COLOUR PALETTE
    ================================================================ */
    const PALETTE = [
        '#2A519F','#f28e0e','#28a745','#dc3545','#6f42c1',
        '#17a2b8','#fd7e14','#20c997','#e83e8c','#6c757d',
        '#0dcaf0','#ffc107','#198754','#d63384','#0d6efd',
        '#adb5bd','#495057','#6610f2','#d4edda','#f8d7da',
    ];
    function palette(n) {
        const arr = [];
        for (let i = 0; i < n; i++) arr.push(PALETTE[i % PALETTE.length]);
        return arr;
    }

    /* ================================================================
       DRAG & DROP / FILE INPUT
    ================================================================ */
    const dropZone   = document.getElementById('drop-zone');
    const fileInput  = document.getElementById('file-input');
    const uploadSec  = document.getElementById('upload-section');
    const analyticsSec = document.getElementById('analytics-section');

    dropZone.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('dragover'); });
    dropZone.addEventListener('dragleave', () => dropZone.classList.remove('dragover'));
    dropZone.addEventListener('drop', e => {
        e.preventDefault();
        dropZone.classList.remove('dragover');
        handleFiles(Array.from(e.dataTransfer.files));
    });
    dropZone.addEventListener('click', e => {
        if (e.target.tagName !== 'LABEL' && e.target.tagName !== 'INPUT') fileInput.click();
    });
    dropZone.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') fileInput.click(); });
    fileInput.addEventListener('change', () => handleFiles(Array.from(fileInput.files)));

    document.getElementById('btn-reset').addEventListener('click', () => {
        allRows = [];
        filteredRows = [];
        fileInput.value = '';
        destroyDataTable();
        resetMapState();
        analyticsSec.classList.add('d-none');
        uploadSec.classList.remove('d-none');
    });

    // Re-adjust column widths when the Data tab becomes visible (fixes scrollX misalignment)
    const tabDataBtn = document.getElementById('tab-data-btn');
    if (tabDataBtn) {
        tabDataBtn.addEventListener('shown.bs.tab', () => {
            if (dataTable) dataTable.columns.adjust().draw();
        });
    }
    const tabMapBtn = document.getElementById('tab-mappa-btn');
    if (tabMapBtn) {
        tabMapBtn.addEventListener('shown.bs.tab', () => {
            initMap();
        });
    }
    const mapContainer = document.getElementById('map-container');
    if (mapContainer) {
        const copyMapPhoneFromTarget = (target) => {
            const btn = target.closest('.badge-phone-map');
            if (!btn) return false;
            const encodedPhone = btn.dataset.phone || '';
            try {
                copyPhone(encodedPhone ? decodeURIComponent(encodedPhone) : '');
                return true;
            } catch (err) {
                console.error('Errore decodifica numero popup mappa:', err);
                showToast('Impossibile copiare il numero.', 'warning');
                return true;
            }
        };
        mapContainer.addEventListener('click', (event) => {
            copyMapPhoneFromTarget(event.target);
        });
        mapContainer.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter' && event.key !== ' ') return;
            if (copyMapPhoneFromTarget(event.target)) event.preventDefault();
        });
    }

    /* ================================================================
       FILE HANDLING & PARSING
    ================================================================ */
    async function handleFiles(files) {
        const validFiles = files.filter(f => /\.(csv|xlsx|xls)$/i.test(f.name));
        if (!validFiles.length) {
            alert('Nessun file valido selezionato. Usa file .csv, .xlsx o .xls');
            return;
        }

        showProgress(true);
        allRows = [];
        let totalParsed = 0;

        for (let i = 0; i < validFiles.length; i++) {
            updateProgressBar(Math.round((i / validFiles.length) * 70));
            updateProgressLabel(`Lettura file ${i + 1}/${validFiles.length}: ${validFiles[i].name}`);
            try {
                const rows = await parseFile(validFiles[i]);
                totalParsed += rows.length;
                updateProgressRows(`Righe elaborate: ${totalParsed}`);
                allRows = allRows.concat(rows);
            } catch (err) {
                console.error('Errore parsing file:', validFiles[i].name, err);
                alert(`Errore nel leggere il file: ${validFiles[i].name}\n${err.message}`);
            }
        }

        updateProgressBar(90);
        updateProgressLabel('Elaborazione dati…');

        // Small delay to allow UI to update
        await sleep(50);
        enrichRows(allRows);
        updateProgressBar(100);
        await sleep(150);

        showProgress(false);
        showAnalytics(validFiles.length, allRows.length);
    }

    function parseFile(file) {
        return new Promise((resolve, reject) => {
            const reader = new FileReader();
            const isExcel = /\.(xlsx|xls)$/i.test(file.name);

            reader.onload = e => {
                try {
                    const data = e.target.result;
                    const wb = XLSX.read(data, { type: 'binary', raw: false, dateNF: 'yyyy-mm-dd' });
                    const ws = wb.Sheets[wb.SheetNames[0]];
                    // header:1 → array of arrays; defval → empty string for missing cells
                    const raw = XLSX.utils.sheet_to_json(ws, { header: 1, defval: '', blankrows: false, raw: false });
                    if (raw.length < 2) { resolve([]); return; }

                    const headers = raw[0].map(h => String(h).trim());
                    const rows = [];
                    for (let i = 1; i < raw.length; i++) {
                        const rowArr = raw[i];
                        const obj = {};
                        headers.forEach((h, idx) => { obj[h] = rowArr[idx] !== undefined ? String(rowArr[idx]).trim() : ''; });
                        // skip completely empty rows
                        if (headers.every(h => !obj[h])) continue;
                        rows.push(obj);
                    }
                    resolve(rows);
                } catch (err) {
                    reject(err);
                }
            };
            reader.onerror = () => reject(new Error('Impossibile leggere il file'));
            reader.readAsBinaryString(file);
        });
    }

    /* ================================================================
       DATA ENRICHMENT
    ================================================================ */
    function enrichRows(rows) {
        rows.forEach(row => {
            // ---- Contacts ----
            const contactsRaw = row['Contatti'] || row['contatti'] || '';
            const parsed = parseContacts(contactsRaw);
            row._phones = parsed.phones;
            row._emails = parsed.emails;
            row._hasPhone = parsed.phones.length > 0;
            row._hasEmail = parsed.emails.length > 0;

            // ---- Codice Fiscale / P.IVA ----
            const cf = (row['Codice Fiscale'] || '').trim().toUpperCase();
            row._cf = cf;
            row._isPiva = isPiva(cf);

            // ---- Gender ----
            row._gender = row._isPiva ? 'A' : deduceGender(cf);

            // ---- Birth date / Age ----
            const dob = parseDob(row['Data Nascita'] || '');
            row._dob = dob;
            row._age = dob ? calcAge(dob) : null;
            row._ageGroup = ageGroup(row._age);
        });
    }

    /* ---- Contact parsing ---- */
    function parseContacts(raw) {
        const phones = [];
        const emails = [];
        if (!raw) return { phones, emails };

        // Extract emails first
        const emailRe = /[a-zA-Z0-9._%+\-]+@[a-zA-Z0-9.\-]+\.[a-zA-Z]{2,}/g;
        let m;
        while ((m = emailRe.exec(raw)) !== null) emails.push(m[0].toLowerCase());

        // Remove emails from string before phone extraction
        const noEmail = raw.replace(emailRe, ' ');

        // Extract Italian phone numbers (mobile: 3xx, landline: 0xx, international +39)
        const phoneRe = /(\+39[\s\-]?)?(\b(3\d{8,9}|0\d{8,11})\b)/g;
        while ((m = phoneRe.exec(noEmail)) !== null) {
            const num = m[0].replace(/[\s\-\/]/g, '');
            if (num.length >= 9) phones.push(num);
        }

        return { phones: [...new Set(phones)], emails: [...new Set(emails)] };
    }

    /* ---- P.IVA detection ---- */
    function isPiva(cf) {
        return /^\d{11}$/.test(cf);
    }

    /* ---- Gender from Italian CF ---- */
    function deduceGender(cf) {
        if (!cf || cf.length < 11) return '?';
        // positions 9-10 (0-indexed: chars at index 9,10) = day of birth
        // for females, day is increased by 40
        const dayStr = cf.substring(9, 11);
        const day = parseInt(dayStr, 10);
        if (isNaN(day)) return '?';
        return day > 40 ? 'F' : 'M';
    }

    /* ---- Date of birth parsing ---- */
    function parseDob(raw) {
        if (!raw) return null;
        raw = String(raw).trim();

        // ISO format YYYY-MM-DD (also handles YYYY-M-D)
        let m = raw.match(/^(\d{4})-(\d{1,2})-(\d{1,2})$/);
        if (m) return new Date(+m[1], +m[2] - 1, +m[3]);

        // DD/MM/YYYY or DD-MM-YYYY
        m = raw.match(/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/);
        if (m) return new Date(+m[3], +m[2] - 1, +m[1]);

        // Only year (4 digits)
        m = raw.match(/^(\d{4})$/);
        if (m) return new Date(+m[1], 0, 1);

        // Try native Date parsing as fallback
        const d = new Date(raw);
        if (!isNaN(d)) return d;

        return null;
    }

    function calcAge(dob) {
        const today = new Date();
        let age = today.getFullYear() - dob.getFullYear();
        const mDiff = today.getMonth() - dob.getMonth();
        if (mDiff < 0 || (mDiff === 0 && today.getDate() < dob.getDate())) age--;
        return age;
    }

    function ageGroup(age) {
        if (age === null) return 'unknown';
        if (age <= 17) return '0-17';
        if (age <= 29) return '18-29';
        if (age <= 44) return '30-44';
        if (age <= 59) return '45-59';
        if (age <= 74) return '60-74';
        return '75+';
    }

    /* ================================================================
       SHOW ANALYTICS
    ================================================================ */
    function showAnalytics(numFiles, numRows) {
        uploadSec.classList.add('d-none');
        analyticsSec.classList.remove('d-none');

        document.getElementById('info-files').textContent = `${numFiles} file`;
        document.getElementById('info-rows').textContent = `${numRows} righe`;

        buildStats();
        buildFilters();
        buildDataTable();
        setTimeout(() => {
            prepareMapData();
        }, MAP_PREPARE_DELAY_MS);
    }

    /* ================================================================
       STATISTICS TAB
    ================================================================ */
    function buildStats() {
        const rows = allRows;

        // KPIs
        const withPhone = rows.filter(r => r._hasPhone);
        const withEmail = rows.filter(r => r._hasEmail);
        const isPivaRows = rows.filter(r => r._isPiva);

        document.getElementById('kpi-total').textContent = rows.length.toLocaleString('it-IT');
        document.getElementById('kpi-phone').textContent = withPhone.length.toLocaleString('it-IT');
        document.getElementById('kpi-email').textContent = withEmail.length.toLocaleString('it-IT');
        document.getElementById('kpi-piva').textContent = isPivaRows.length.toLocaleString('it-IT');

        // ----- Chart: contacts -----
        buildPieChart('chart-contacts', {
            labels: ['Con telefono', 'Solo email', 'Senza contatti'],
            data: [
                withPhone.length,
                rows.filter(r => !r._hasPhone && r._hasEmail).length,
                rows.filter(r => !r._hasPhone && !r._hasEmail).length,
            ],
            colors: ['#28a745', '#17a2b8', '#dee2e6'],
        });

        // ----- Chart: gender -----
        const genders = countBy(rows, r => r._gender);
        buildPieChart('chart-gender', {
            labels: Object.keys(genders).map(labelGender),
            data: Object.values(genders),
            colors: Object.keys(genders).map(g => g === 'M' ? '#0d6efd' : g === 'F' ? '#e83e8c' : g === 'A' ? '#6c757d' : '#adb5bd'),
        });

        // ----- Chart: age distribution -----
        const ageBuckets = ['0-17','18-29','30-44','45-59','60-74','75+','unknown'];
        const ageCounts = ageBuckets.map(b => rows.filter(r => r._ageGroup === b).length);
        buildBarChart('chart-age', {
            labels: ageBuckets.map(b => b === 'unknown' ? 'N/D' : b),
            data: ageCounts,
            label: 'Intestatari',
            color: '#2A519F',
        });

        // ----- Chart: province -----
        const provinces = countBy(rows, r => (r['Provincia'] || '').trim() || 'N/D');
        const provSorted = sortedEntries(provinces);
        buildPieChart('chart-province', {
            labels: provSorted.map(e => e[0]),
            data: provSorted.map(e => e[1]),
            colors: palette(provSorted.length),
        });

        // ----- Chart: top 10 cities -----
        const cities = countBy(rows, r => (r['Comune'] || '').trim() || 'N/D');
        const citySorted = sortedEntries(cities).slice(0, 10);
        buildBarChart('chart-city', {
            labels: citySorted.map(e => e[0]),
            data: citySorted.map(e => e[1]),
            label: 'Immobili',
            color: '#f28e0e',
        });

        // ----- Chart: category -----
        const cats = countBy(rows, r => (r['Categoria'] || '').trim() || 'N/D');
        const catSorted = sortedEntries(cats);
        buildPieChart('chart-category', {
            labels: catSorted.map(e => e[0]),
            data: catSorted.map(e => e[1]),
            colors: palette(catSorted.length),
        });

        // ----- Chart: ownership -----
        const own = countBy(rows, r => (r['Titolarita'] || r['Titolarità'] || '').trim() || 'N/D');
        const ownSorted = sortedEntries(own);
        buildPieChart('chart-ownership', {
            labels: ownSorted.map(e => e[0]),
            data: ownSorted.map(e => e[1]),
            colors: palette(ownSorted.length),
        });

        // ----- P.IVA table -----
        buildPivaTable(isPivaRows);
    }

    function labelGender(g) {
        return { M: 'Maschio', F: 'Femmina', A: 'Azienda', '?': 'Non det.' }[g] || g;
    }

    function countBy(arr, fn) {
        const res = {};
        arr.forEach(item => {
            const key = fn(item);
            res[key] = (res[key] || 0) + 1;
        });
        return res;
    }

    function sortedEntries(obj) {
        return Object.entries(obj).sort((a, b) => b[1] - a[1]);
    }

    /* ---- Chart builders ---- */
    function destroyChart(id) {
        if (charts[id]) { charts[id].destroy(); delete charts[id]; }
    }

    function buildPieChart(id, { labels, data, colors }) {
        destroyChart(id);
        const ctx = document.getElementById(id).getContext('2d');
        charts[id] = new Chart(ctx, {
            type: 'doughnut',
            data: {
                labels,
                datasets: [{ data, backgroundColor: colors, borderWidth: 2, borderColor: '#fff' }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'right', labels: { boxWidth: 14, font: { size: 11 } } },
                    tooltip: {
                        callbacks: {
                            label: ctx => {
                                const total = ctx.dataset.data.reduce((a, b) => a + b, 0);
                                const pct = total ? ((ctx.parsed / total) * 100).toFixed(1) : 0;
                                return ` ${ctx.label}: ${ctx.parsed.toLocaleString('it-IT')} (${pct}%)`;
                            }
                        }
                    }
                },
            },
        });
    }

    function buildBarChart(id, { labels, data, label, color }) {
        destroyChart(id);
        const ctx = document.getElementById(id).getContext('2d');
        charts[id] = new Chart(ctx, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label,
                    data,
                    backgroundColor: color + 'cc',
                    borderColor: color,
                    borderWidth: 1,
                    borderRadius: 4,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: { label: ctx => ` ${ctx.parsed.y.toLocaleString('it-IT')}` } },
                },
                scales: {
                    y: { beginAtZero: true, ticks: { precision: 0 } },
                    x: { ticks: { font: { size: 11 } } },
                },
            },
        });
    }

    /* ---- P.IVA table ---- */
    function buildPivaTable(pivaRows) {
        const thead = document.querySelector('#table-piva thead');
        const tbody = document.querySelector('#table-piva tbody');
        const noMsg = document.getElementById('no-piva-msg');
        thead.innerHTML = '';
        tbody.innerHTML = '';

        if (!pivaRows.length) {
            document.getElementById('table-piva').classList.add('d-none');
            noMsg.classList.remove('d-none');
            return;
        }
        document.getElementById('table-piva').classList.remove('d-none');
        noMsg.classList.add('d-none');

        const cols = ['Provincia','Comune','Indirizzo','Civico','Categoria','Codice Fiscale','Titolarita','Quota'];
        const tr = document.createElement('tr');
        cols.forEach(c => { const th = document.createElement('th'); th.textContent = c; tr.appendChild(th); });
        thead.appendChild(tr);

        pivaRows.forEach(row => {
            const tr = document.createElement('tr');
            cols.forEach(c => {
                const td = document.createElement('td');
                // Handle accented column name variant for Titolarita
                const val = (c === 'Titolarita')
                    ? (row['Titolarita'] || row['Titolarità'] || '')
                    : (row[c] || '');
                td.textContent = val;
                tr.appendChild(td);
            });
            tbody.appendChild(tr);
        });
    }

    /* ================================================================
       DATA TAB – FILTERS & TABLE
    ================================================================ */
    function buildFilters() {
        populateSelect('filter-province', [...new Set(allRows.map(r => (r['Provincia'] || '').trim()).filter(Boolean))].sort());
        populateSelect('filter-city',     [...new Set(allRows.map(r => (r['Comune'] || '').trim()).filter(Boolean))].sort());
        populateSelect('filter-category', [...new Set(allRows.map(r => (r['Categoria'] || '').trim()).filter(Boolean))].sort());
        populateSelect('filter-ownership',[...new Set(allRows.map(r => (r['Titolarita'] || r['Titolarità'] || '').trim()).filter(Boolean))].sort());

        // Events
        ['filter-phone','filter-email','filter-province','filter-city',
         'filter-category','filter-ownership','filter-age','filter-gender','filter-piva']
            .forEach(id => document.getElementById(id).addEventListener('change', applyFilters));

        document.getElementById('btn-clear-filters').addEventListener('click', () => {
            ['filter-phone','filter-email','filter-province','filter-city',
             'filter-category','filter-ownership','filter-age','filter-gender','filter-piva']
                .forEach(id => document.getElementById(id).value = '');
            applyFilters();
        });

        document.getElementById('table-data').addEventListener('click', (event) => {
            const btn = event.target.closest('.btn-copy-phone');
            if (!btn) return;
            const encodedPhone = btn.dataset.phone || '';
            try {
                copyPhone(encodedPhone ? decodeURIComponent(encodedPhone) : '');
            } catch (err) {
                console.error('Errore decodifica numero:', err);
                showToast('Impossibile copiare il numero.', 'warning');
            }
        });

        applyFilters();
    }

    function populateSelect(id, values) {
        const sel = document.getElementById(id);
        // keep first "All" option
        while (sel.options.length > 1) sel.remove(1);
        values.forEach(v => {
            const opt = document.createElement('option');
            opt.value = v; opt.textContent = v;
            sel.appendChild(opt);
        });
    }

    function applyFilters() {
        const phone    = document.getElementById('filter-phone').value;
        const email    = document.getElementById('filter-email').value;
        const province = document.getElementById('filter-province').value;
        const city     = document.getElementById('filter-city').value;
        const category = document.getElementById('filter-category').value;
        const ownership= document.getElementById('filter-ownership').value;
        const age      = document.getElementById('filter-age').value;
        const gender   = document.getElementById('filter-gender').value;
        const piva     = document.getElementById('filter-piva').value;

        filteredRows = allRows.filter(row => {
            if (phone    === '1' && !row._hasPhone) return false;
            if (phone    === '0' &&  row._hasPhone) return false;
            if (email    === '1' && !row._hasEmail) return false;
            if (email    === '0' &&  row._hasEmail) return false;
            if (province && (row['Provincia'] || '').trim() !== province) return false;
            if (city     && (row['Comune'] || '').trim()    !== city)     return false;
            if (category && (row['Categoria'] || '').trim() !== category) return false;
            if (ownership) {
                const own = (row['Titolarita'] || row['Titolarità'] || '').trim();
                if (own !== ownership) return false;
            }
            if (age      && row._ageGroup !== age) return false;
            if (gender   && row._gender   !== gender) return false;
            if (piva     === '1' && !row._isPiva) return false;
            if (piva     === '0' &&  row._isPiva) return false;
            return true;
        });

        document.getElementById('data-count-label').textContent =
            `${filteredRows.length.toLocaleString('it-IT')} righe visualizzate`;

        refreshDataTable();
    }

    /* ================================================================
       DATA TABLE (DataTables.js)
    ================================================================ */
    // Columns visible in data tab
    const DATA_COLUMNS = [
        { key: 'Provincia',       label: 'Provincia' },
        { key: 'Comune',          label: 'Comune' },
        { key: 'Indirizzo',       label: 'Indirizzo' },
        { key: 'Civico',          label: 'Civico' },
        { key: 'Piano',           label: 'Piano' },
        { key: 'Categoria',       label: 'Categoria' },
        { key: 'Nome',            label: 'Cognome' },
        { key: 'Nome1',           label: 'Nome' },
        { key: 'Codice Fiscale',  label: 'CF / P.IVA' },
        { key: 'Data Nascita',    label: 'Data Nascita' },
        { key: '_age',            label: 'Età', render: v => v === null ? '' : v },
        { key: '_gender',         label: 'Sesso', render: renderGender },
        { key: 'Titolarita',      label: 'Titolarità', fallback: 'Titolarità' },
        { key: 'Quota',           label: 'Quota' },
        { key: '_phones',         label: 'Telefono', render: renderPhones },
        { key: '_emails',         label: 'Email', render: renderEmails },
        { key: 'Rendita',         label: 'Rendita' },
        { key: 'Foglio',          label: 'Foglio' },
        { key: 'Particella',      label: 'Particella' },
        { key: 'Subalterno',      label: 'Sub.' },
    ];

    function getCellValue(row, col) {
        if (col.key.startsWith('_')) return row[col.key];
        const v = row[col.key];
        if ((v === undefined || v === '') && col.fallback) return row[col.fallback] || '';
        return v !== undefined ? v : '';
    }

    function renderGender(g) {
        const map = { M: '<span class="badge-gender-m">M</span>',
                      F: '<span class="badge-gender-f">F</span>',
                      A: '<span class="badge-gender-a">AZ</span>' };
        return map[g] || '';
    }

    function renderPhones(phones) {
        if (!phones || !phones.length) return '';
        return phones.map(p => {
            const phone = String(p || '');
            const escapedPhone = htmlEscape(phone);
            const encodedPhone = encodeURIComponent(phone);
            return `
                <span class="phone-item">
                    <span class="badge-phone">${escapedPhone}</span>
                    <button type="button" class="btn-copy-phone" data-phone="${encodedPhone}" aria-label="Copia numero" title="Copia numero di telefono">
                        <i class="bi bi-clipboard"></i>
                    </button>
                </span>
            `;
        }).join(' ');
    }

    function renderEmails(emails) {
        if (!emails || !emails.length) return '';
        return emails.map(e => `<span class="badge-email">${e}</span>`).join(' ');
    }

    function buildDataTable() {
        // Build thead
        const thead = document.querySelector('#table-data thead');
        thead.innerHTML = '';
        const tr = document.createElement('tr');
        DATA_COLUMNS.forEach(col => {
            const th = document.createElement('th');
            th.textContent = col.label;
            tr.appendChild(th);
        });
        thead.appendChild(tr);

        // Init DataTable with empty data first
        dataTable = $('#table-data').DataTable({
            data: [],
            columns: DATA_COLUMNS.map(col => ({
                title: col.label,
                data: null,
                defaultContent: '',
                render: (data, type, row) => {
                    const v = getCellValue(row, col);
                    if (type === 'display') return col.render ? col.render(v) : (v === null || v === undefined ? '' : htmlEscape(String(v)));
                    // For sorting/filtering use raw value
                    if (Array.isArray(v)) return v.join(' ');
                    return v === null || v === undefined ? '' : String(v);
                },
            })),
            pageLength: 50,
            lengthMenu: [[25, 50, 100, 250, -1], [25, 50, 100, 250, 'Tutti']],
            language: italianDT(),
            order: [],
            scrollX: false,
            autoWidth: true,
            dom: '<"row mb-2"<"col-sm-6"l><"col-sm-6"f>>rt<"row mt-2"<"col-sm-5"i><"col-sm-7"p>>',
        });

        refreshDataTable();
    }

    function refreshDataTable() {
        if (!dataTable) return;
        dataTable.clear();
        dataTable.rows.add(filteredRows);
        dataTable.columns.adjust().draw(false);
    }

    function destroyDataTable() {
        if (dataTable) {
            dataTable.destroy();
            dataTable = null;
            document.querySelector('#table-data thead').innerHTML = '';
            document.querySelector('#table-data tbody').innerHTML = '';
        }
    }

    /* ================================================================
       MAP TAB – BACKGROUND GEOCODING + LEAFLET
    ================================================================ */
    async function prepareMapData() {
        if (geocodingInProgress || !allRows.length) return;
        geocodingInProgress = true;
        mapDataReady = false;
        mapHasGeocodeErrors = false;
        geocodeServiceUnavailable = false;

        const grouped = groupRowsByAddress(allRows);
        mapGroupedData = Object.values(grouped);
        const total = mapGroupedData.length;

        if (!total) {
            geocodingInProgress = false;
            mapDataReady = true;
            updateMapStatus('error');
            return;
        }

        updateMapStatus('preparing', 0, total);

        // 1. Gather unique comuni
        const comuniMap = new Map();
        mapGroupedData.forEach(p => {
            if (p.comune && p.provincia) comuniMap.set(p.comune.toUpperCase(), p.provincia);
        });

        // 2. Query each comune via WFS API (with cache)
        for (const [comune, provincia] of comuniMap.entries()) {
            try {
                const scanResult = await scanComuneParticelle(comune, provincia);
                if (scanResult && scanResult.ok && scanResult.particelle) {
                    Object.assign(particelleCache, scanResult.particelle);
                    console.log(`✅ [WFS] ${comune}: ${scanResult.particelle_found} particelle trovate`);
                }
            } catch (err) {
                console.warn(`⚠️ [WFS] ${comune}: query non disponibile, uso fallback Nominatim`);
            }
        }

        // 3. Build a reverse lookup index: foglio|particella → first matching cache key
        //    This allows O(1) lookup per parcel instead of scanning all cache keys each time.
        const fpIndex = {}; // "FOGLIO|PARTICELLA" → cacheKey
        for (const cacheKey of Object.keys(particelleCache)) {
            const parts = cacheKey.split('|');
            if (parts.length === 3) {
                const fpKey = `${parts[1]}|${parts[2]}`;
                if (!fpIndex[fpKey]) fpIndex[fpKey] = cacheKey;
            }
        }

        // 4. Apply AdE coordinates; collect unmatched for Nominatim fallback
        const unmatched = [];
        mapGroupedData.forEach(addr => {
            const foglio     = addr.foglio;
            const particella = addr.particella;
            const comune     = addr.comune.toUpperCase();

            if (foglio && particella) {
                // Try exact comune+foglio+particella key first, then foglio+particella index
                const exactKey = `${comune}|${foglio}|${particella}`;
                const fpKey    = `${foglio}|${particella}`;
                const matchKey = particelleCache[exactKey] ? exactKey : fpIndex[fpKey];

                if (matchKey && particelleCache[matchKey]) {
                    addr.lat    = parseFloat(particelleCache[matchKey].lat);
                    addr.lng    = parseFloat(particelleCache[matchKey].lng);
                    addr.source = 'AdE';
                    return;
                }
            }

            unmatched.push(addr);
        });

        const adeMatched = mapGroupedData.length - unmatched.length;
        console.log(`✅ [Map] ${adeMatched}/${mapGroupedData.length} particelle matchate con AdE`);

        // 5. Nominatim fallback for unmatched
        let completed = adeMatched;
        if (unmatched.length > 0) {
            console.log(`🔄 [Fallback] Geocoding ${unmatched.length} indirizzi con Nominatim…`);
            for (let i = 0; i < unmatched.length; i++) {
                const addr = unmatched[i];
                const cacheKey = getAddressKey(addr);
                if (geocodedAddresses[cacheKey]) {
                    addr.lat    = geocodedAddresses[cacheKey].lat;
                    addr.lng    = geocodedAddresses[cacheKey].lng;
                    addr.source = 'Nominatim';
                    completed++;
                    updateMapProgress(completed, total);
                    continue;
                }

                const query = buildGeoQuery(addr);
                let coords = await geocodeAddress(query, GEOCODE_MAX_ATTEMPTS);
                if (!coords) {
                    const fallbackQuery = `${addr.comune}, ${addr.provincia}, Italia`;
                    coords = await geocodeAddress(fallbackQuery, GEOCODE_MAX_ATTEMPTS);
                }

                if (coords) {
                    addr.lat    = coords.lat;
                    addr.lng    = coords.lng;
                    addr.source = 'Nominatim';
                    geocodedAddresses[cacheKey] = coords;
                } else {
                    mapHasGeocodeErrors = true;
                }

                completed++;
                updateMapProgress(completed, total);
            }

            // Apply jitter to parcels that ended up on identical coordinates
            applyJitterToDuplicates(unmatched);
        }

        geocodingInProgress = false;
        mapDataReady = true;

        const geocodedCount = mapGroupedData.filter(item => Number.isFinite(item.lat) && Number.isFinite(item.lng)).length;
        if (geocodeServiceUnavailable) showToast('Servizio geocodificazione non disponibile', 'warning');
        if (!geocodedCount) {
            updateMapStatus('error', completed, total, 'Impossibile geocodificare gli indirizzi. Verifica connessione internet.');
            return;
        }

        if (mapHasGeocodeErrors) {
            updateMapStatus('error', completed, total);
        } else {
            updateMapStatus('ready', completed, total);
        }

        if (mapInstance) initMap();
    }

    async function scanComuneParticelle(comune, provincia) {
        console.log(`🔍 [WFS] Inizio query WFS per ${comune} (${provincia})`);
        try {
            const checkUrl = `api/get_particelle_wfs.php?comune=${encodeURIComponent(comune)}&provincia=${encodeURIComponent(provincia)}&mode=check`;
            const checkResp = await fetch(checkUrl);
            if (!checkResp.ok) {
                const errorText = await checkResp.text();
                console.warn(`⚠️ [WFS] Check cache fallito (${checkResp.status}):`, errorText);
                throw new Error(`HTTP ${checkResp.status}`);
            }
            const checkData = await checkResp.json();

            if (checkData.cached && !checkData.needs_update) {
                console.log(`✅ [Cache] Usando cache WFS per ${comune} (${checkData.particelle_count} particelle, ${checkData.cache_age_days} giorni)`);
                const fetchUrl = `api/get_particelle_wfs.php?comune=${encodeURIComponent(comune)}&provincia=${encodeURIComponent(provincia)}&mode=fetch`;
                const fetchResp = await fetch(fetchUrl);
                if (!fetchResp.ok) throw new Error(`HTTP ${fetchResp.status}`);
                return await fetchResp.json();
            }

            if (!checkData.cached || checkData.needs_update) {
                console.log('⏳ [WFS] Cache non disponibile, avvio query WFS (~30-60 sec)...');
                showScanProgress(comune, 0, 10, 0);
            }

            return await new Promise((resolve, reject) => {
                const streamUrl = `api/get_particelle_wfs.php?comune=${encodeURIComponent(comune)}&provincia=${encodeURIComponent(provincia)}&mode=stream`;
                const eventSource = new EventSource(streamUrl);

                const progressTimeout = setTimeout(() => {
                    eventSource.close();
                    console.warn('⚠️ [WFS] Timeout stream');
                    reject(new Error('WFS timeout'));
                }, WFS_STREAM_TIMEOUT_MS);

                eventSource.onmessage = (event) => {
                    const data = JSON.parse(event.data);
                    if (data.complete) {
                        clearTimeout(progressTimeout);
                        eventSource.close();
                        hideScanProgress();
                        resolve(data.result);
                    } else if (data.error) {
                        clearTimeout(progressTimeout);
                        eventSource.close();
                        hideScanProgress();
                        reject(new Error(data.error));
                    } else {
                        updateScanProgress(comune, data.tiles_queried, data.total_tiles, data.found);
                    }
                };

                eventSource.onerror = (err) => {
                    clearTimeout(progressTimeout);
                    eventSource.close();
                    hideScanProgress();
                    console.warn('⚠️ [WFS] Stream error:', err);
                    reject(new Error('WFS stream failed'));
                };
            });
        } catch (err) {
            console.warn(`⚠️ [WFS] Query ${comune} fallita (${err.message}), uso fallback Nominatim`);
            throw err;
        }
    }

    function showScanProgress(comune, tilesQueried, totalTiles, found) {
        const progressDiv = document.getElementById('scan-progress');
        if (!progressDiv) return;
        progressDiv.classList.remove('d-none');
        const percent = totalTiles > 0 ? Math.round((tilesQueried / totalTiles) * 100) : 0;
        const eta = totalTiles > 0 ? Math.ceil((totalTiles - tilesQueried) * WFS_SECONDS_PER_TILE) : 1;
        document.getElementById('scan-comune-name').textContent = comune;
        document.getElementById('scan-points').textContent = `${tilesQueried}/${totalTiles} tiles`;
        document.getElementById('scan-found').textContent = found;
        document.getElementById('scan-eta').textContent = eta > 0 ? `~${eta} sec` : 'Quasi fatto!';
        document.getElementById('scan-progress-bar').style.width = `${percent}%`;
    }

    function updateScanProgress(comune, scanned, total, found) {
        showScanProgress(comune, scanned, total, found);
    }

    function hideScanProgress() {
        const progressDiv = document.getElementById('scan-progress');
        if (progressDiv) {
            setTimeout(() => progressDiv.classList.add('d-none'), 2000);
        }
    }

    async function geocodeAddress(query, retries) {
        const maxAttempts = Math.max(1, Number(retries) || 1);
        for (let attempt = 0; attempt < maxAttempts; attempt++) {
            try {
                const now = Date.now();
                const waitMs = GEOCODE_RATE_LIMIT_MS - (now - lastGeocodeRequestAt);
                if (waitMs > 0) await sleep(waitMs);
                lastGeocodeRequestAt = Date.now();

                const url = `https://nominatim.openstreetmap.org/search?q=${encodeURIComponent(query)}&format=json&limit=1&email=${encodeURIComponent(NOMINATIM_CONTACT_EMAIL)}`;
                const response = await fetch(url, {
                    headers: {
                        Accept: 'application/json',
                        'User-Agent': NOMINATIM_USER_AGENT,
                    },
                });
                if (!response.ok) {
                    if (response.status >= 500) geocodeServiceUnavailable = true;
                    throw new Error(`HTTP ${response.status}`);
                }
                const data = await response.json();
                if (Array.isArray(data) && data.length) {
                    return {
                        lat: Number(data[0].lat),
                        lng: Number(data[0].lon),
                    };
                }
                console.warn('Geocode failed for:', query);
            } catch (error) {
                if (String(error?.message || '').includes('Failed to fetch')) {
                    geocodeServiceUnavailable = true;
                }
                console.warn('Geocode failed for:', query, error);
            }

            if (attempt < maxAttempts - 1) await sleep(GEOCODE_RETRY_DELAY_MS);
        }
        return null;
    }

    function buildGeoQuery(addressData) {
        const address = [addressData.indirizzo, addressData.civico].filter(Boolean).join(' ').trim();
        return `${address}, ${addressData.comune}, ${addressData.provincia}, Italia`;
    }

    function initMap() {
        const mapEl = document.getElementById('map-container');
        if (!mapEl || typeof L === 'undefined') return;

        if (!mapInstance) {
            mapInstance = L.map('map-container').setView([45.5, 10.5], 8);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap',
                maxZoom: 19,
            }).addTo(mapInstance);
            mapMarkers = L.markerClusterGroup({
                maxClusterRadius: MAP_CLUSTER_RADIUS_PIXELS,
                spiderfyOnMaxZoom: true,
                showCoverageOnHover: true,
            });
            mapInstance.addLayer(mapMarkers);
        }

        mapInstance.invalidateSize();
        if (!mapDataReady) {
            updateMapStatus('preparing');
            return;
        }
        renderMapMarkers();
    }

    function renderMapMarkers() {
        if (!mapInstance || !mapMarkers) return;
        mapMarkers.clearLayers();

        const geocoded = mapGroupedData.filter(item => Number.isFinite(item.lat) && Number.isFinite(item.lng));
        geocoded.forEach(addressData => {
            const marker = L.marker([addressData.lat, addressData.lng]);
            marker.bindPopup(createMarkerPopup(addressData), { maxWidth: 460 });
            mapMarkers.addLayer(marker);
        });

        if (!geocoded.length) {
            updateMapStatus('error', 0, mapGroupedData.length, 'Impossibile geocodificare gli indirizzi. Verifica connessione internet.');
            return;
        }

        const bounds = mapMarkers.getBounds();
        if (bounds.isValid()) mapInstance.fitBounds(bounds.pad(0.15));
    }

    function createMarkerPopup(addressData) {
        const fullAddress = `${addressData.indirizzo} ${addressData.civico}`.trim();

        // Sort intestatari by subalterno
        const intestatari = [...(addressData.intestatari || [])].sort((a, b) =>
            (a.subalterno || '').localeCompare(b.subalterno || '')
        );

        const sourceIcon = addressData.source === 'AdE'
            ? `<i class="bi bi-geo-alt-fill text-success ms-1" title="Coordinate precise da Agenzia delle Entrate"></i>`
            : addressData.jittered
                ? `<i class="bi bi-geo-alt text-warning ms-1" title="Coordinate approssimative con offset applicato"></i>`
                : `<i class="bi bi-geo-alt text-warning ms-1" title="Coordinate approssimative da Nominatim"></i>`;

        const rows = intestatari.map(item => {
            const phones = (item.telefoni || []).map(phone => {
                const safePhone = htmlEscape(phone);
                const encodedPhone = encodeURIComponent(phone);
                return `<span class="badge-phone-map" data-phone="${htmlEscape(encodedPhone)}" role="button" tabindex="0"><i class="bi bi-clipboard"></i>${safePhone}</span>`;
            }).join(' ');

            const addrNote = (item.indirizzo && item.civico &&
                (item.indirizzo !== addressData.indirizzo || item.civico !== addressData.civico))
                ? `<br><small class="text-muted fst-italic">${htmlEscape(item.indirizzo)} ${htmlEscape(item.civico)}</small>`
                : '';

            return `
                <tr>
                    <td>${htmlEscape(item.nome || '')}${addrNote}</td>
                    <td class="text-center">${htmlEscape(item.subalterno || '—')}</td>
                    <td>${htmlEscape(item.categoria || '—')}</td>
                    <td>${phones || '<span class="text-muted">-</span>'}</td>
                </tr>
            `;
        }).join('');

        const catInfo = (addressData.foglio && addressData.particella)
            ? `<div class="small mb-2 p-2" style="background:#f8f9fa;border-radius:6px;border-left:3px solid #f28e0e;">
                   <strong>Particella Catastale:</strong>
                   Foglio ${htmlEscape(addressData.foglio)}
                   · Particella ${htmlEscape(addressData.particella)}
               </div>`
            : '';

        return `
            <div class="map-popup">
                <h6><i class="bi bi-house-fill me-1"></i>${htmlEscape(fullAddress)}${sourceIcon}</h6>
                <div class="small text-muted mb-2">${htmlEscape(addressData.comune)} (${htmlEscape(addressData.provincia)})</div>
                ${catInfo}
                <div class="map-popup-table-wrap">
                    <table class="table table-sm table-striped mb-1">
                        <thead class="table-light">
                            <tr>
                                <th>Intestatario</th>
                                <th style="width:50px;">Sub.</th>
                                <th>Cat.</th>
                                <th>Telefono</th>
                            </tr>
                        </thead>
                        <tbody>${rows}</tbody>
                    </table>
                </div>
                <div class="small text-muted mt-1 d-flex justify-content-between">
                    <span>${intestatari.length} unità immobiliari</span>
                    <span>${addressData.source === 'AdE' ? '✓ Coordinate precise' : addressData.jittered ? '⚠ Coordinate stimate' : '⚠ Coordinate approssimative'}</span>
                </div>
            </div>
        `;
    }

    /**
     * Aggiunge piccolo offset casuale alle coordinate quando particelle diverse
     * hanno stesse coordinate (geocodifica Nominatim imprecisa).
     * Jitter: ±0.0001° (circa ±11 metri)
     */
    function applyJitterToDuplicates(addresses) {
        const coordsMap = new Map(); // "lat,lng" → [addr1, addr2, ...]

        // Group by coordinates
        addresses.forEach(addr => {
            if (Number.isFinite(addr.lat) && Number.isFinite(addr.lng)) {
                const key = `${addr.lat.toFixed(6)},${addr.lng.toFixed(6)}`;
                if (!coordsMap.has(key)) coordsMap.set(key, []);
                coordsMap.get(key).push(addr);
            }
        });

        // Apply jitter to duplicates
        coordsMap.forEach((addrs, coordKey) => {
            if (addrs.length > 1) {
                console.log(`🔀 [Jitter] ${addrs.length} particelle su stesse coordinate ${coordKey}, applico offset`);
                addrs.forEach((addr, idx) => {
                    addr.jittered = true; // All parcels at same coords are estimated
                    if (idx > 0) { // First entry keeps original coordinates
                        const angle = (Math.PI * 2 * idx) / addrs.length;
                        const distance = 0.0001; // ~11 metri
                        addr.lat += Math.cos(angle) * distance;
                        addr.lng += Math.sin(angle) * distance;
                    }
                });
            }
        });
    }

    function groupRowsByAddress(rows) {
        const grouped = {};
        rows.forEach(row => {
            const foglio     = String(row['Foglio']     || '').trim();
            const particella = String(row['Particella'] || '').trim();
            const comune     = String(row['Comune']     || '').trim();
            const provincia  = String(row['Provincia']  || '').trim();

            if (!comune || !provincia) return;

            // Primary key: Comune + Foglio + Particella (identifies a unique cadastral parcel)
            // Fall back to address key when cadastral data is absent
            const key = (foglio && particella)
                ? `${comune}|${foglio}|${particella}`.toUpperCase()
                : getAddressKey({ indirizzo: String(row['Indirizzo'] || '').trim(), civico: String(row['Civico'] || '').trim(), comune, provincia });

            if (!grouped[key]) {
                grouped[key] = {
                    indirizzo:  String(row['Indirizzo'] || '').trim(),
                    civico:     String(row['Civico']    || '').trim(),
                    comune,
                    provincia,
                    foglio,
                    particella,
                    lat:        null,
                    lng:        null,
                    source:     null,
                    intestatari: [],
                };
            }

            const nomeCompleto = [row[COLUMN_SURNAME], row[COLUMN_GIVEN_NAME]].filter(Boolean).join(' ').trim() || '-';
            grouped[key].intestatari.push({
                nome:       nomeCompleto,
                telefoni:   Array.isArray(row._phones) ? row._phones : [],
                email:      Array.isArray(row._emails) ? row._emails : [],
                foglio,
                particella,
                subalterno: String(row['Subalterno'] || '').trim(),
                categoria:  String(row['Categoria']  || '').trim(),
                indirizzo:  String(row['Indirizzo']  || '').trim(),
                civico:     String(row['Civico']     || '').trim(),
            });
        });
        return grouped;
    }

    function getAddressKey(addressData) {
        return `${addressData.indirizzo}|${addressData.civico}|${addressData.comune}|${addressData.provincia}`.toUpperCase();
    }

    function updateMapProgress(current, total) {
        const progressBar = document.getElementById('map-progress-bar');
        const statusText = document.getElementById('map-status-text');
        if (!progressBar || !statusText) return;
        const pct = total ? ((current / total) * 100).toFixed(1) : '0.0';
        progressBar.style.width = `${pct}%`;
        statusText.textContent = `Preparazione mappa in corso… ${current}/${total} indirizzi`;
    }

    function updateMapStatus(state, current, total, customMessage) {
        const status = document.getElementById('map-status');
        const statusText = document.getElementById('map-status-text');
        const progressBar = document.getElementById('map-progress-bar');
        if (!status || !statusText || !progressBar) return;
        if (mapStatusHideTimer) {
            clearTimeout(mapStatusHideTimer);
            mapStatusHideTimer = null;
        }

        status.classList.remove('d-none', 'alert-info', 'alert-success', 'alert-warning');
        if (state === 'preparing') {
            status.classList.add('alert-info');
            updateMapProgress(current || 0, total || mapGroupedData.length || 0);
            return;
        }

        if (state === 'ready') {
            status.classList.add('alert-success');
            progressBar.style.width = '100%';
            statusText.textContent = '✓ Mappa pronta!';
            mapStatusHideTimer = setTimeout(() => status.classList.add('d-none'), 2000);
            return;
        }

        status.classList.add('alert-warning');
        progressBar.style.width = total ? `${Math.round((current / total) * 100)}%` : progressBar.style.width;
        statusText.textContent = customMessage || 'Alcuni indirizzi non sono stati geocodificati';
    }

    function resetMapState() {
        mapDataReady = false;
        mapGroupedData = [];
        geocodedAddresses = {};
        particelleCache = {};
        geocodingInProgress = false;
        mapHasGeocodeErrors = false;
        geocodeServiceUnavailable = false;
        lastGeocodeRequestAt = 0;
        if (mapStatusHideTimer) {
            clearTimeout(mapStatusHideTimer);
            mapStatusHideTimer = null;
        }
        const status = document.getElementById('map-status');
        const progressBar = document.getElementById('map-progress-bar');
        if (status) status.classList.add('d-none');
        if (progressBar) progressBar.style.width = '0%';
        if (mapInstance) {
            mapInstance.remove();
            mapInstance = null;
            mapMarkers = null;
        }
    }

    /* ================================================================
       COPY PHONE
    ================================================================ */
    function copyPhone(phone) {
        const rawPhone = String(phone || '').trim();
        if (!rawPhone) {
            showToast('Numero non disponibile.', 'warning');
            return;
        }
        const safePhone = rawPhone.replace(/[^\d+]/g, '') || rawPhone;
        if (!safePhone.trim()) {
            showToast('Numero non valido.', 'warning');
            return;
        }

        navigator.clipboard.writeText(safePhone).then(() => {
            showToast(`Numero copiato: ${safePhone}`, 'success');
        }).catch(() => {
            // Fallback for browsers without clipboard API
            const ta = document.createElement('textarea');
            ta.value = safePhone;
            ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            showToast(`Numero copiato: ${safePhone}`, 'success');
        });
    }

    /* ================================================================
       HELPERS
    ================================================================ */
    function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

    function htmlEscape(str) {
        return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
                  .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
    }

    function showProgress(show) {
        const el = document.getElementById('upload-progress');
        if (show) el.classList.remove('d-none');
        else el.classList.add('d-none');
    }

    function updateProgressBar(pct) {
        const bar = document.getElementById('progress-bar');
        const lbl = document.getElementById('progress-percent');
        bar.style.width = pct + '%';
        lbl.textContent = pct + '%';
    }

    function updateProgressLabel(text) {
        document.getElementById('progress-label').textContent = text;
    }

    function updateProgressRows(text) {
        document.getElementById('progress-rows').textContent = text;
    }

    function showToast(msg, type) {
        const toast = document.getElementById('copy-toast');
        const msgEl = document.getElementById('copy-toast-msg');
        toast.className = `toast align-items-center text-bg-${type === 'warning' ? 'warning' : 'success'} border-0`;
        msgEl.textContent = msg;
        bootstrap.Toast.getOrCreateInstance(toast, { delay: 3000 }).show();
    }

    function italianDT() {
        return {
            sEmptyTable:     'Nessun dato disponibile nella tabella',
            sInfo:           'Vista da _START_ a _END_ di _TOTAL_ elementi',
            sInfoEmpty:      'Vista da 0 a 0 di 0 elementi',
            sInfoFiltered:   '(filtrati da _MAX_ elementi totali)',
            sLengthMenu:     'Mostra _MENU_ righe',
            sLoadingRecords: 'Caricamento…',
            sProcessing:     'Elaborazione…',
            sSearch:         'Cerca:',
            sZeroRecords:    'La ricerca non ha portato alcun risultato.',
            oPaginate: {
                sFirst:    '«', sLast: '»', sNext: '›', sPrevious: '‹',
            },
        };
    }

})();
