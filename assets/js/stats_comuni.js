/**
 * stats_comuni.js
 * Frontend module for the "Statistiche Comuni" tab.
 * Reads comuni from allRows (global app.js state), calls api/get_stats_comune.php
 * and renders KPI cards + comparative charts.
 */
(function () {
    'use strict';

    /* ================================================================
       STATE
    ================================================================ */
    let statsCharts = {};   // Chart.js instances for this tab
    let statsCache  = {};   // Loaded stats keyed by "comune|provincia"

    /* ================================================================
       INIT – called once when the tab becomes visible
    ================================================================ */
    function init() {
        const tab = document.getElementById('tab-stats-comuni-btn');
        if (!tab) return;

        tab.addEventListener('shown.bs.tab', onTabShown);

        document.getElementById('btn-load-stats')?.addEventListener('click', onLoadStats);
        document.getElementById('btn-clear-stats')?.addEventListener('click', onClearStats);
    }

    function onTabShown() {
        populateComuniDropdown();
    }

    /* ================================================================
       POPULATE DROPDOWN
    ================================================================ */
    function populateComuniDropdown() {
        const select = document.getElementById('comuni-loaded');
        if (!select) return;

        // allRows is declared in app.js scope; access via window to be safe
        const rows = (typeof allRows !== 'undefined' ? allRows : null)
            || (typeof window.allRows !== 'undefined' ? window.allRows : []);

        if (!rows.length) {
            select.innerHTML = '<option disabled>Carica prima un file CSV/Excel</option>';
            return;
        }

        // Extract unique (comune, provincia) pairs
        const comuniMap = new Map();
        rows.forEach(row => {
            const c = String(row['Comune']    || row['comune']    || '').trim();
            const p = String(row['Provincia'] || row['provincia'] || '').trim().toUpperCase();
            if (c && p) {
                const key = `${c.toUpperCase()}|${p}`;
                if (!comuniMap.has(key)) {
                    comuniMap.set(key, { comune: c, provincia: p });
                }
            }
        });

        if (!comuniMap.size) {
            select.innerHTML = '<option disabled>Nessun comune trovato nei dati caricati</option>';
            return;
        }

        // Sort alphabetically
        const sorted = Array.from(comuniMap.values()).sort((a, b) =>
            a.comune.localeCompare(b.comune, 'it')
        );

        // Preserve current selection
        const previouslySelected = new Set(
            Array.from(select.selectedOptions).map(o => o.value)
        );

        select.innerHTML = sorted.map(({ comune, provincia }) => {
            const val     = `${comune}|${provincia}`;
            const label   = `${comune} (${provincia})`;
            const selected = previouslySelected.has(val) ? ' selected' : '';
            return `<option value="${escHtml(val)}"${selected}>${escHtml(label)}</option>`;
        }).join('');
    }

    /* ================================================================
       LOAD STATS
    ================================================================ */
    async function onLoadStats() {
        const select = document.getElementById('comuni-loaded');
        if (!select) return;

        const selected = Array.from(select.selectedOptions).map(o => o.value);
        if (!selected.length) {
            showStatsAlert('Seleziona almeno un comune prima di caricare le statistiche.', 'warning');
            return;
        }

        showLoading(true);
        clearDashboard();

        const results = [];
        for (const val of selected) {
            const [comune, provincia] = val.split('|');
            try {
                const data = await fetchStats(comune, provincia);
                results.push(data);
                renderComuneCard(data);
            } catch (err) {
                renderErrorCard(comune, provincia, err.message);
            }
        }

        showLoading(false);

        // Show comparison charts when more than one commune is loaded
        if (results.length > 1) {
            renderComparisonCharts(results);
        } else {
            document.getElementById('stats-comuni-comparison')?.classList.add('d-none');
        }
    }

    async function fetchStats(comune, provincia) {
        const cacheKey = `${comune.toUpperCase()}|${provincia.toUpperCase()}`;
        if (statsCache[cacheKey]) return statsCache[cacheKey];

        const params = new URLSearchParams({ comune, provincia });
        const resp   = await fetch(`api/get_stats_comune.php?${params.toString()}`);

        if (!resp.ok) {
            const err = await resp.json().catch(() => ({}));
            throw new Error(err.error || `HTTP ${resp.status}`);
        }

        const data = await resp.json();
        if (!data.ok) throw new Error(data.error || 'Errore sconosciuto');

        statsCache[cacheKey] = data;
        return data;
    }

    /* ================================================================
       RENDER – KPI CARD
    ================================================================ */
    function renderComuneCard(data) {
        const dashboard = document.getElementById('stats-comuni-dashboard');
        if (!dashboard) return;

        const dem  = data.demografia  || {};
        const eco  = data.economia    || {};
        const imm  = data.immobiliare || {};

        const pop     = dem.popolazione              != null ? Number(dem.popolazione).toLocaleString('it-IT')                            : '–';
        const reddito = eco.reddito_medio_irpef       != null ? Number(eco.reddito_medio_irpef).toLocaleString('it-IT') + ' €'             : '–';
        const imprese = eco.numero_imprese             != null ? Number(eco.numero_imprese).toLocaleString('it-IT')                         : '–';
        const prezzom2 = imm.prezzo_mq_medio_residenziale != null ? Number(imm.prezzo_mq_medio_residenziale).toLocaleString('it-IT') + ' €/m²' : '–';

        const noticeHtml = data.notice
            ? `<div class="alert alert-warning py-2 mb-2 small"><i class="bi bi-exclamation-triangle me-1"></i>${escHtml(data.notice)}</div>`
            : '';

        const card = document.createElement('div');
        card.className = 'col-12 col-xl-6';
        card.innerHTML = `
            <div class="chart-card">
                <h6 class="chart-title">
                    <i class="bi bi-pin-map-fill me-2"></i>${escHtml(data.nome)} (${escHtml(data.provincia)})
                    ${data.regione ? '<small class="text-muted fw-normal ms-2">' + escHtml(data.regione) + '</small>' : ''}
                </h6>
                ${noticeHtml}
                <div class="row g-2 mb-3">
                    <div class="col-6 col-md-3">
                        <div class="kpi-card kpi-card--small">
                            <div class="kpi-icon"><i class="bi bi-people-fill"></i></div>
                            <div class="kpi-value">${escHtml(pop)}</div>
                            <div class="kpi-label">Popolazione</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="kpi-card kpi-card--small kpi-card--orange">
                            <div class="kpi-icon"><i class="bi bi-cash-stack"></i></div>
                            <div class="kpi-value">${escHtml(reddito)}</div>
                            <div class="kpi-label">Reddito Medio</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="kpi-card kpi-card--small">
                            <div class="kpi-icon"><i class="bi bi-shop"></i></div>
                            <div class="kpi-value">${escHtml(imprese)}</div>
                            <div class="kpi-label">Imprese</div>
                        </div>
                    </div>
                    <div class="col-6 col-md-3">
                        <div class="kpi-card kpi-card--small kpi-card--orange">
                            <div class="kpi-icon"><i class="bi bi-house-fill"></i></div>
                            <div class="kpi-value">${escHtml(prezzom2)}</div>
                            <div class="kpi-label">Valore Immobiliare</div>
                        </div>
                    </div>
                </div>
                ${renderDemografiaDetail(dem)}
                <div class="small text-muted mt-2">
                    <i class="bi bi-info-circle me-1"></i>
                    Aggiornato al ${escHtml(data.ultimo_aggiornamento || '–')} &middot;
                    Fonte: ${escHtml(data.fonte_dati || '–')}
                </div>
            </div>
        `;

        dashboard.appendChild(card);
    }

    function renderDemografiaDetail(dem) {
        const fasceEta = dem.fasce_eta;
        if (!fasceEta || typeof fasceEta !== 'object') return '';

        const bars = Object.entries(fasceEta)
            .map(([label, pct]) =>
                `<div class="d-flex align-items-center gap-2 small mb-1">
                    <span style="width:40px;color:#6c757d;">${escHtml(label)}</span>
                    <div class="flex-grow-1 bg-light rounded" style="height:8px;">
                        <div style="width:${Math.min(100, pct)}%;height:8px;background:#2A519F;border-radius:4px;"></div>
                    </div>
                    <span style="width:38px;text-align:right;">${pct}%</span>
                </div>`
            ).join('');

        return bars ? `<div class="mb-2"><div class="small fw-semibold text-muted mb-1">Fasce età</div>${bars}</div>` : '';
    }

    function renderErrorCard(comune, provincia, message) {
        const dashboard = document.getElementById('stats-comuni-dashboard');
        if (!dashboard) return;

        const card = document.createElement('div');
        card.className = 'col-12 col-xl-6';
        card.innerHTML = `
            <div class="chart-card">
                <h6 class="chart-title text-danger">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>${escHtml(comune)} (${escHtml(provincia)})
                </h6>
                <div class="alert alert-danger mb-0">
                    <i class="bi bi-wifi-off me-1"></i>
                    Impossibile caricare i dati: ${escHtml(message)}
                </div>
            </div>
        `;
        dashboard.appendChild(card);
    }

    /* ================================================================
       COMPARISON CHARTS (multi-comune)
    ================================================================ */
    function renderComparisonCharts(results) {
        const section = document.getElementById('stats-comuni-comparison');
        if (!section) return;

        section.classList.remove('d-none');

        const labels = results.map(d => d.nome);

        // Popolazione chart
        const popData = results.map(d => d.demografia?.popolazione ?? 0);
        renderBarChart('chart-compare-popolazione', labels, popData, 'Popolazione');

        // Reddito chart
        const redData = results.map(d => d.economia?.reddito_medio_irpef ?? 0);
        renderBarChart('chart-compare-reddito', labels, redData, 'Reddito Medio (€)', '#f28e0e');
    }

    function renderBarChart(canvasId, labels, data, label, color = '#2A519F') {
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;

        if (statsCharts[canvasId]) {
            statsCharts[canvasId].destroy();
        }

        statsCharts[canvasId] = new Chart(canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label,
                    data,
                    backgroundColor: color,
                    borderRadius: 6,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: (ctx) => `${label}: ${ctx.raw != null ? Number(ctx.raw).toLocaleString('it-IT') : '–'}`,
                        },
                    },
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        ticks: {
                            callback: (v) => Number(v).toLocaleString('it-IT'),
                        },
                    },
                },
            },
        });
    }

    /* ================================================================
       CLEAR
    ================================================================ */
    function onClearStats() {
        clearDashboard();
        document.getElementById('stats-comuni-comparison')?.classList.add('d-none');
        Object.values(statsCharts).forEach(c => c.destroy());
        statsCharts = {};
    }

    function clearDashboard() {
        const dashboard = document.getElementById('stats-comuni-dashboard');
        if (dashboard) dashboard.innerHTML = '';
    }

    /* ================================================================
       UI HELPERS
    ================================================================ */
    function showLoading(visible) {
        const el = document.getElementById('stats-loading');
        if (!el) return;
        el.classList.toggle('d-none', !visible);
    }

    function showStatsAlert(message, type = 'info') {
        const dashboard = document.getElementById('stats-comuni-dashboard');
        if (!dashboard) return;
        dashboard.innerHTML = `
            <div class="col-12">
                <div class="alert alert-${type}">
                    <i class="bi bi-info-circle me-2"></i>${escHtml(message)}
                </div>
            </div>
        `;
    }

    function escHtml(str) {
        return String(str ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    /* ================================================================
       PUBLIC API
    ================================================================ */
    window.statsComuni = {
        init,
        populateComuniDropdown,
        clearStats: onClearStats,
    };

    // Auto-init when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
