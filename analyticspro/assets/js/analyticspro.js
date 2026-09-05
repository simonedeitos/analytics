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
        adeJobsEndpoint: root.dataset.adeJobsEndpoint || '',
        adeManualFilesEndpoint: root.dataset.adeManualFilesEndpoint || '',
        propertyDeleteEndpoint: root.dataset.propertyDeleteEndpoint || '',
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
    };

    state.mapStatiFilter = Object.keys(STATE_OPTIONS).slice();
    state.mapCategoriaFilter = null;

    function getStatiFilter() {
        if (state.mapStatiFilter && Array.isArray(state.mapStatiFilter) && state.mapStatiFilter.length > 0) {
            return state.mapStatiFilter;
        }
        return Object.keys(STATE_OPTIONS).slice();
    }

    function getCategorieFilter() {
        return Array.isArray(state.mapCategoriaFilter) ? state.mapCategoriaFilter : null;
    }

    function paletteEntryByColor(color) {
        return MARKER_COLOR_PALETTE.find(function (item) { return item.value.toLowerCase() === String(color || '').toLowerCase(); }) || null;
    }

    function escapeHtml(value) {
        return String(value === null || value === undefined ? '' : value).replace(/[&<>'"]/g, function (char) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[char];
        });
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
            in_vendita_noi: '#fd7e14',
            in_vendita_altri: '#ffc107',
            altro: '#6f42c1',
        };
        return map[stateKey] || '#0d6efd';
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
        if (!phones.length) {
            return '<span class="text-muted small">Nessun telefono</span>';
        }
        return '<div class="d-flex flex-wrap gap-1 mt-1">' + phones.map(function (phone) {
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
        }).join('') + '</div>';
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

    async function loadProperties() {
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
        populateAssignedSubuserFilter();
        refreshMapCategoryFilters();
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
            return '<div class="mb-1"><div>' + escapeHtml(fullName) + '</div>' + (state.canViewPhone ? buildPhoneChips(owner.telefono) : '') + '</div>';
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
            var foglio     = String(p.foglio     || '');
            var particella = String(p.particella || '');
            var subalterno = String(p.subalterno || '');
            var unitKey    = foglio + '|' + particella + '|' + subalterno + '|' + String(p.user_id || '');

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
                        allOwners.push(owners[oi]);
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
        }

        var statiFilter = getStatiFilter();
        var categorieFilter = getCategorieFilter();
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
            marker.bindPopup(buildPopupHtml(property), { maxWidth: 460 });
            state.markers.addLayer(marker);
            points.push(property);
        }

        if (points.length) state.map.fitBounds(state.markers.getBounds().pad(0.2));
        setTimeout(function () { if (state.map) state.map.invalidateSize(); }, 150);
        setTimeout(function () { if (state.map) state.map.invalidateSize(); }, 600);
    }

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
            if (owner.data_nascita)                   profileParts.push('Nato il: ' + formatDobWithAge(owner.data_nascita));
            if (owner.genere)                         profileParts.push('Genere: ' + owner.genere);
            var quota      = property.quota      ? ' (' + escapeHtml(property.quota)      + ')' : '';
            var titolarita = property.titolarita ? ' \u2013 ' + escapeHtml(property.titolarita) : '';
            return '<div class="property-owner-row">'
                + '<div class="fw-semibold">' + escapeHtml(fullName) + quota + titolarita + '</div>'
                + (identityParts.length ? '<div class="owner-meta">' + escapeHtml(identityParts.join(' | ')) + '</div>' : '')
                + (profileParts.length  ? '<div class="owner-meta">' + escapeHtml(profileParts.join(' | '))  + '</div>' : '')
                + (state.canViewPhone && owner.telefono ? buildPhoneChips(owner.telefono) : '')
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
            return '<div class="small mb-1"><strong>' + escapeHtml(note.author_name_snapshot || '') + '</strong> &middot; ' + escapeHtml(note.created_at || '') + '<br>' + escapeHtml(note.testo || '') + '</div>';
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

    function pieChart(id, labels, data) {
        var ctx = document.getElementById(id);
        if (!ctx) return;
        state.charts[id] = new Chart(ctx, { type: 'pie', data: { labels: labels, datasets: [{ data: data }] }, options: { responsive: true } });
    }

    function barChart(id, labels, data, label) {
        var ctx = document.getElementById(id);
        if (!ctx) return;
        state.charts[id] = new Chart(ctx, { type: 'bar', data: { labels: labels, datasets: [{ label: label, data: data, backgroundColor: '#0d6efd' }] }, options: { responsive: true, plugins: { legend: { display: false } } } });
    }

    function renderCharts() {
        destroyCharts();
        var owners = [];
        state.properties.forEach(function (p) { (p.owners || []).forEach(function (o) { owners.push(o); }); });
        var withPhone = 0;
        if (state.canViewPhone) withPhone = owners.filter(function (o) { return o.telefono; }).length;
        var withEmail = owners.filter(function (o) { return o.email; }).length;
        var withPiva  = owners.filter(function (o) { return o.tipo === 'azienda'; }).length;
        var genders = {}, provinces = {}, comuni = {}, categories = {}, ownership = {}, ages = {};
        owners.forEach(function (o) { var k = o.genere || 'N/D'; genders[k] = (genders[k] || 0) + 1; });
        state.properties.forEach(function (p) {
            var kp = p.provincia || 'N/D'; provinces[kp] = (provinces[kp] || 0) + 1;
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
        pieChart('chart-province',  Object.keys(provinces),  Object.keys(provinces).map(function(k){return provinces[k];}));
        barChart('chart-comune',    Object.keys(comuni).slice(0,10), Object.keys(comuni).slice(0,10).map(function(k){return comuni[k];}), 'Immobili');
        pieChart('chart-categoria', Object.keys(categories), Object.keys(categories).map(function(k){return categories[k];}));
        pieChart('chart-titolarita',Object.keys(ownership),  Object.keys(ownership).map(function(k){return ownership[k];}));
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
        await loadProperties();
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

    function applyOwnerPhoneUpdate(propertyId, ownerId, updatedPhoneRaw) {
        [state.properties, state.assignedProperties || []].forEach(function (collection) {
            (collection || []).forEach(function (property) {
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
            data[field.name] = String(field.value || '').trim();
        });
        return data;
    }

    function manualRecordFormHasValues() {
        var data = manualRecordFormData();
        return Object.keys(data).some(function (key) { return data[key] !== ''; });
    }

    function resetManualRecordForm() {
        var form = document.getElementById('manual-record-form');
        var feedback = document.getElementById('manual-record-feedback');
        if (form) form.reset();
        if (feedback) {
            feedback.className = 'alert d-none py-2';
            feedback.textContent = '';
        }
    }

    function initManualRecordModal() {
        var modalEl = document.getElementById('manual-record-modal');
        var openBtn = document.getElementById('open-manual-record-modal');
        var saveBtn = document.getElementById('save-manual-record-btn');
        if (!modalEl || !openBtn || !saveBtn) return;
        var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
        var allowClose = false;
        openBtn.addEventListener('click', function () {
            allowClose = false;
            modal.show();
        });
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
                await loadProperties();
                window.alert('Record salvato correttamente.');
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

    async function parseFiles(files) {
        var parsedRows = [];
        for (var fi = 0; fi < files.length; fi++) {
            var file   = files[fi];
            var buffer = await file.arrayBuffer();
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
        return parsedRows;
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

    async function runImport(files) {
        importLoggerReset();
        importLog('info', 'Fase Lettura file avviata');
        var rows = await parseFiles(files);
        if (!rows.length) { setImportPhase('Completato', 100, 'Nessuna riga valida trovata'); importLog('warning', 'Nessuna riga valida trovata.'); return; }
        setImportPhase('Lettura file', 10, importProgressLabel(0, rows.length) + ' · Righe lette: ' + rows.length);
        importLog('info', 'Righe lette: ' + rows.length);
        setImportPhase('Analisi duplicati', 20, importProgressLabel(0, rows.length) + ' · Analisi duplicati in corso...');
        var analysis = await api(state.importEndpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ csrf_token: state.csrfToken, mode: 'analyze', rows: rows }) });
        importLog('info', 'Duplicati rilevati: ' + (analysis.conflicts || []).length);
        var decisions = {};
        for (var ci = 0; ci < (analysis.conflicts || []).length; ci++) {
            var conflict = analysis.conflicts[ci];
            var confirmUpdate = window.confirm('Duplicato per ' + conflict.comune + ' F.' + conflict.foglio + ' P.' + conflict.particella + (conflict.subalterno ? '/' + conflict.subalterno : '') + '.\nNuovo intestatario: ' + (conflict.incoming_owner || conflict.new_owner || 'N/D') + '.\nSostituire?');
            decisions[conflict.row_index] = confirmUpdate ? 'updated' : 'kept_old';
        }
        setImportPhase('Salvataggio dati', 45, importProgressLabel(0, rows.length) + ' · Salvataggio dati in corso...');
        importLog('info', 'Fase Salvataggio dati avviata');
        var processPayload = await api(state.importEndpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ csrf_token: state.csrfToken, mode: 'process', filename: Array.from(files).map(function(f){return f.name;}).join(', '), decisions: decisions, rows: rows }) });
        state.currentImportStats = {
            savedRows: Number(processPayload.saved_rows || 0),
            totalRows: Number(processPayload.total_rows || rows.length)
        };
        setImportPhase('Geolocalizzazione', 80, importProgressLabel(state.currentImportStats.savedRows, state.currentImportStats.totalRows) + ' · Geolocalizzazione particelle in corso...');
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
        importLog('info', 'Particelle geolocalizzate: ' + (processPayload.geolocated_parcels || 0));
        await loadProperties();
        renderEnrichmentReport({ coord_source: processPayload.coord_source || {}, failure_codes: processPayload.failure_codes || {}, unresolved_rows: processPayload.unresolved_rows || [], truncated: !!processPayload.unresolved_truncated });
        if (processPayload.batch_id) {
            if (processPayload.enrichment_done) { setImportPhase('Completato', 100, importProgressLabel(state.currentImportStats.savedRows, state.currentImportStats.totalRows) + ' · Completato'); importLog('info', 'Geolocalizzazione completata.'); }
            else { importLog('warning', 'Particelle residue: ' + (processPayload.remaining_unique_parcels || 0)); await enrichChunkLoop(processPayload.batch_id); }
        }
    }

    async function pollEnrichment(batchId) {
        var container = document.getElementById('enrichment-status-container');
        var bar = document.getElementById('import-progress-bar');
        var text = document.getElementById('import-progress-text');
        var reportEl = document.getElementById('enrichment-report');
        if (container) container.style.display = '';
        if (reportEl) { reportEl.className = 'small mt-2 d-none'; reportEl.innerHTML = ''; }
        var maxIterations = 240, iterations = 0, lastProcessed = -1, stalledSince = 0;
        while (iterations < maxIterations) {
            iterations++;
            var batch;
            try { var p2 = await api(state.importProgressEndpoint + '?batch_id=' + batchId); batch = p2.batch; } catch(e) { await new Promise(function(r){setTimeout(r,3000);}); continue; }
            var status = batch.enrichment_status || null, processed = batch.enrichment_processed || 0, total = batch.enrichment_total || 0;
            var pct = total > 0 ? Math.round(processed / total * 100) : 0;
            var progressPrefix = state.currentImportStats
                ? importProgressLabel(state.currentImportStats.savedRows, state.currentImportStats.totalRows) + ' · '
                : '';
            renderEnrichmentReport(batch.enrichment_report);
            if (bar) bar.style.width = Math.max(80, clampPercent(pct)) + '%';
            if (text) text.textContent = progressPrefix + 'Geolocalizzazione: ' + processed + '/' + total + ' (' + clampPercent(pct) + '%)';
            if (status === 'completed') { if (text) text.textContent = progressPrefix + 'Geolocalizzazione completata.'; if (bar) bar.style.width = '100%'; setImportPhase('Completato', 100, progressPrefix + 'Completato'); try { await loadProperties(); } catch(e){} if (container) setTimeout(function(){container.style.display='none';},4000); return; }
            if (status === 'failed') { if (text) { text.textContent = 'Geolocalizzazione non riuscita.'; text.classList.add('text-danger'); } if (bar) bar.classList.replace('bg-primary','bg-danger'); return; }
            if (status === 'pending' && processed === 0) { stalledSince++; if (stalledSince >= 6 && state.enrichChunkEndpoint) { enrichChunkLoop(batchId).catch(function(){}); return; } }
            else if (processed !== lastProcessed) { stalledSince = 0; lastProcessed = processed; }
            await new Promise(function(r){setTimeout(r,2500);});
        }
        if (text) text.textContent = 'Timeout. Usa "Rigenera coordinate mancanti".';
    }

    async function enrichChunkLoop(batchId) {
        var container = document.getElementById('enrichment-status-container');
        var bar = document.getElementById('import-progress-bar');
        var text = document.getElementById('import-progress-text');
        var reportEl = document.getElementById('enrichment-report');
        if (container) container.style.display = '';
        if (reportEl) { reportEl.className = 'small mt-2 d-none'; reportEl.innerHTML = ''; }
        var maxChunks = 500, calls = 0;
        setImportPhase('Geolocalizzazione', 80, (state.currentImportStats ? importProgressLabel(state.currentImportStats.savedRows, state.currentImportStats.totalRows) + ' · ' : '') + 'Geolocalizzazione sincrona...');
        importLog('info', 'Avvio fallback chunk sincrono');
        while (calls < maxChunks) {
            calls++;
            var result;
            try { result = await api(state.enrichChunkEndpoint + '?batch_id=' + batchId + '&limit=25', { allowErrorPayload: true }); }
            catch(e) { await new Promise(function(r){setTimeout(r,2000);}); continue; }
            if (result.ok === false) {
                var errCode = result.error_code || 'unknown';
                if (errCode === 'transient') { await new Promise(function(r){setTimeout(r,2000);}); continue; }
                if (text) { text.textContent = 'Errore [' + errCode + ']: ' + (result.error || 'Errore sconosciuto'); text.classList.add('text-danger'); }
                if (bar) bar.classList.replace('bg-primary','bg-danger');
                return;
            }
            var p2 = result.processed || 0, t2 = result.total || 0;
            var pct2 = t2 > 0 ? Math.round(p2 / t2 * 100) : (result.done ? 100 : 0);
            var progressPrefix = state.currentImportStats
                ? importProgressLabel(state.currentImportStats.savedRows, state.currentImportStats.totalRows) + ' · '
                : '';
            renderEnrichmentReport(result.enrichment_report);
            if (bar) bar.style.width = clampPercent(pct2) + '%';
            if (text) text.textContent = progressPrefix + 'Geolocalizzazione: ' + p2 + '/' + t2 + ' (' + clampPercent(pct2) + '%)';
            importLog('info', 'Chunk ' + calls + ': ' + p2 + '/' + t2);
            if (result.done || result.status === 'completed') { if (text) text.textContent = progressPrefix + 'Geolocalizzazione completata: ' + p2 + '/' + t2 + '.'; if (bar) bar.style.width = '100%'; setImportPhase('Completato', 100, progressPrefix + 'Completato'); importLog('info', 'Completato.'); try { await loadProperties(); } catch(e){} if (container) setTimeout(function(){container.style.display='none';},4000); return; }
            if (result.status === 'failed') { if (text) { text.textContent = 'Non riuscita.'; text.classList.add('text-danger'); } if (bar) bar.classList.replace('bg-primary','bg-danger'); return; }
            await new Promise(function(r){setTimeout(r,200);});
        }
        if (text) text.textContent = 'Limite chunk raggiunto. Usa "Rigenera coordinate mancanti".';
    }

    function renderEnrichmentReport(report) {
        var el = document.getElementById('enrichment-report');
        if (!el || !report || typeof report !== 'object') { if (el) { el.className = 'small mt-2 d-none'; el.innerHTML = ''; } return; }
        var sourceEntries  = Object.keys(report.coord_source  || {}).filter(function(k){return Number(report.coord_source[k])>0;});
        var failureEntries = Object.keys(report.failure_codes || {}).filter(function(k){return Number(report.failure_codes[k])>0;});
        var unresolved     = Array.isArray(report.unresolved_rows) ? report.unresolved_rows : [];
        if (!sourceEntries.length && !failureEntries.length && !unresolved.length) { el.className = 'small mt-2 d-none'; el.innerHTML = ''; return; }
        var html = [];
        if (sourceEntries.length)  html.push('<div><strong>Sorgenti:</strong> '   + sourceEntries.map(function(k){return escapeHtml(k)+'='+escapeHtml(String(report.coord_source[k]));}).join(' &middot; ')  + '</div>');
        if (failureEntries.length) html.push('<div class="mt-1"><strong>Fallimenti:</strong> ' + failureEntries.map(function(k){return escapeHtml(k)+'='+escapeHtml(String(report.failure_codes[k]));}).join(' &middot; ') + '</div>');
        if (unresolved.length)     html.push('<ul class="mb-0 mt-2 ps-3">' + unresolved.map(function(i){return '<li>'+escapeHtml(String(i))+'</li>';}).join('') + (report.truncated ? '<li>&hellip;</li>' : '') + '</ul>');
        el.className = 'small';
        el.innerHTML = html.join('');
    }

    function ensureSharedModals() {
        if (!document.getElementById('property-editor-modal')) {
            var editorModal = document.createElement('div');
            editorModal.innerHTML = '<div class="modal fade" id="property-editor-modal" tabindex="-1" aria-hidden="true"><div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">Modifica marker</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button></div><div class="modal-body"><div id="property-editor-meta" class="small text-muted mb-3"></div><div id="property-editor-error" class="alert alert-danger py-2 px-3 small d-none mb-3"></div><div id="editor-owners-block" class="mb-3"><label id="editor-owners-label" class="form-label small mb-1">Intestatari e telefoni</label><div id="editor-owners-content"></div></div><div class="row g-2"><div class="col-md-6"><label class="form-label small mb-1">Stato</label><select id="editor-state" class="form-select form-select-sm"></select></div><div class="col-md-6"><label class="form-label small mb-1">Colore marker</label><div class="d-flex align-items-center gap-2"><span id="editor-color-preview" class="color-dot" style="width:18px;height:18px;"></span><select id="editor-color" class="form-select form-select-sm"></select></div></div><div class="col-12"><label class="form-label small mb-1">Stato personalizzato</label><input id="editor-custom-state" class="form-control form-control-sm" placeholder="Stato personalizzato"></div><div class="col-12"><label class="form-label small mb-1">Assegnazioni</label><div id="editor-assignments-summary" class="small"></div></div><div class="col-12"><button type="button" class="btn btn-outline-secondary btn-sm d-none" id="editor-assignments-open"><i class="bi bi-person-plus me-1"></i>Gestisci assegnazioni</button></div><div class="col-12"><label class="form-label small mb-1">Nota</label><textarea id="editor-note" class="form-control form-control-sm" rows="3" placeholder="Aggiungi nota"></textarea></div></div></div><div class="modal-footer"><button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Annulla</button><button type="button" class="btn btn-primary btn-sm" id="editor-save-btn">Salva</button></div></div></div></div>';
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
        if (!owners.length) {
            return '<div class="text-muted small">Nessun intestatario disponibile.</div>';
        }
        return owners.map(function (owner) {
            var fullName = ((owner.cognome || '') + ' ' + (owner.nome || '')).trim() || 'Intestatario';
            var identityParts = [];
            if (owner.codice_fiscale) identityParts.push('CF/P.IVA: ' + owner.codice_fiscale);
            if (owner.email) identityParts.push('\u2709 ' + owner.email);
            if (owner.indirizzo) identityParts.push('\uD83D\uDCCD ' + owner.indirizzo);
            return '<div class="border rounded p-2 mb-2">'
                + '<div class="fw-semibold small">' + escapeHtml(fullName) + '</div>'
                + (identityParts.length ? '<div class="owner-meta small">' + escapeHtml(identityParts.join(' | ')) + '</div>' : '')
                + (state.canViewPhone
                    ? '<div class="mt-1"><span class="small text-muted">Telefoni</span>'
                        + buildEditablePhoneChips(owner.telefono, property.id, owner.id || 0, !!property.can_edit && Number(owner.id || 0) > 0)
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
            label.textContent = state.canViewPhone ? 'Intestatari e telefoni' : 'Intestatari';
        }
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
            if (!window.confirm('Eliminare il numero ' + phone + '? L\'operazione è irreversibile.')) return;
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
    });

    var assignedSaveBtn = document.getElementById('assigned-save');
    if (assignedSaveBtn) assignedSaveBtn.addEventListener('click', async function () { await loadProperties(); });
    ['report-filter-comune','report-filter-foglio','report-filter-assigned'].forEach(function (id) { var el = document.getElementById(id); if (el) el.addEventListener('input', function () { applyReportFilters(); }); });

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
        var btn = document.getElementById('rigenera-coordinate-btn');
        if (!btn || !state.enrichChunkEndpoint) return;
        btn.addEventListener('click', async function () {
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>In corso...';
            var container = document.getElementById('enrichment-status-container');
            var bar = document.getElementById('import-progress-bar');
            var text = document.getElementById('import-progress-text');
            if (container) { container.style.display=''; if(bar){bar.style.width='0%';bar.className='progress-bar bg-primary progress-bar-striped progress-bar-animated';} if(text){text.textContent='Rigenera coordinate...';text.className='small mb-2';} }
            setImportPhase('Geolocalizzazione', 0, 'Rigenera coordinate...');
            importLog('info', 'Rigenera coordinate mancanti avviato.');
            try { await enrichChunkLoop(0); } catch(e) {}
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-geo-alt me-1"></i>Rigenera coordinate mancanti';
        });
    })();
})();
