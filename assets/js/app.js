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
        analyticsSec.classList.add('d-none');
        uploadSec.classList.remove('d-none');
    });

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
                    <button type="button" class="btn-copy-phone" data-phone="${encodedPhone}" aria-label="Copia numero di telefono" title="Copia numero di telefono">
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
            scrollX: true,
            autoWidth: false,
            dom: '<"row mb-2"<"col-sm-6"l><"col-sm-6"f>>rt<"row mt-2"<"col-sm-5"i><"col-sm-7"p>>',
        });

        refreshDataTable();
    }

    function refreshDataTable() {
        if (!dataTable) return;
        dataTable.clear();
        dataTable.rows.add(filteredRows);
        dataTable.draw();
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
       COPY PHONE
    ================================================================ */
    function copyPhone(phone) {
        if (!phone) {
            showToast('Numero non disponibile.', 'warning');
            return;
        }

        navigator.clipboard.writeText(phone).then(() => {
            showToast(`Numero copiato: ${phone}`, 'success');
        }).catch(() => {
            // Fallback for browsers without clipboard API
            const ta = document.createElement('textarea');
            ta.value = phone;
            ta.style.position = 'fixed'; ta.style.opacity = '0';
            document.body.appendChild(ta);
            ta.select();
            document.execCommand('copy');
            document.body.removeChild(ta);
            showToast(`Numero copiato: ${phone}`, 'success');
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
