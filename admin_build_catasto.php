<?php
require_once __DIR__ . '/api/catasto_admin_access.php';

catastoEnforceAdminAccess(false);

$province = [
    'AGRIGENTO', 'ALESSANDRIA', 'ANCONA', 'AOSTA', 'AREZZO', 'ASCOLI-PICENO',
    'ASTI', 'AVELLINO', 'BARI', 'BARLETTA-ANDRIA-TRANI', 'BELLUNO', 'BENEVENTO',
    'BERGAMO', 'BIELLA', 'BOLOGNA', 'BOLZANO', 'BRESCIA', 'BRINDISI',
    'CAGLIARI', 'CALTANISSETTA', 'CAMPOBASSO', 'CASERTA', 'CATANIA', 'CATANZARO',
    'CHIETI', 'COMO', 'COSENZA', 'CREMONA', 'CROTONE', 'CUNEO',
    'ENNA', 'FERMO', 'FERRARA', 'FIRENZE', 'FOGGIA', 'FORLI-CESENA',
    'FROSINONE', 'GENOVA', 'GORIZIA', 'GROSSETO', 'IMPERIA', 'ISERNIA',
    'LA-SPEZIA', 'LAQUILA', 'LATINA', 'LECCE', 'LECCO', 'LIVORNO',
    'LODI', 'LUCCA', 'MACERATA', 'MANTOVA', 'MASSA-CARRARA', 'MATERA',
    'MESSINA', 'MILANO', 'MODENA', 'MONZA-E-DELLA-BRIANZA', 'NAPOLI', 'NOVARA',
    'NUORO', 'ORISTANO', 'PADOVA', 'PALERMO', 'PARMA', 'PAVIA',
    'PERUGIA', 'PESARO-E-URBINO', 'PESCARA', 'PIACENZA', 'PISA', 'PISTOIA',
    'PORDENONE', 'POTENZA', 'PRATO', 'RAGUSA', 'RAVENNA', 'REGGIO-DI-CALABRIA',
    'REGGIO-NELLEMILIA', 'RIETI', 'RIMINI', 'ROMA', 'ROVIGO', 'SALERNO',
    'SASSARI', 'SAVONA', 'SIENA', 'SIRACUSA', 'SONDRIO', 'SUD-SARDEGNA',
    'TARANTO', 'TERAMO', 'TERNI', 'TORINO', 'TRAPANI', 'TRENTO',
    'TREVISO', 'TRIESTE', 'UDINE', 'VARESE', 'VENEZIA', 'VERBANO-CUSIO-OSSOLA',
    'VERCELLI', 'VERONA', 'VIBO-VALENTIA', 'VICENZA', 'VITERBO'
];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Build Catasto Italia</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #5f2c82, #49a09d);
            font-family: 'Segoe UI', system-ui, sans-serif;
        }
        .admin-shell {
            max-width: 1200px;
            margin: 0 auto;
            padding: 32px 16px 48px;
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.95);
            border-radius: 20px;
            box-shadow: 0 20px 50px rgba(0, 0, 0, 0.15);
            border: 1px solid rgba(255,255,255,.35);
        }
        .stats-card {
            padding: 20px;
            height: 100%;
        }
        .stats-value {
            font-size: 2rem;
            font-weight: 700;
            color: #4a2a75;
        }
        .progress {
            height: 30px;
            border-radius: 999px;
            background: #ece7f6;
        }
        .progress-bar {
            font-weight: 700;
            font-size: .95rem;
            background: linear-gradient(90deg, #7b2cbf, #9d4edd);
        }
        .province-list {
            max-height: 560px;
            overflow-y: auto;
        }
        .province-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 14px;
            background: #f8f5ff;
            border: 1px solid #ece4fb;
            margin-bottom: 10px;
        }
        .province-item.waiting { border-left: 5px solid #adb5bd; }
        .province-item.processing { border-left: 5px solid #ffc107; background: #fff8df; }
        .province-item.success { border-left: 5px solid #198754; background: #eaf8f0; }
        .province-item.error { border-left: 5px solid #dc3545; background: #fff1f3; }
        .province-name { font-weight: 700; color: #3c2a5f; }
        .province-meta { font-size: .85rem; color: #6c757d; }
        .control-btn { min-width: 170px; }
        .footer-warning { border-radius: 16px; }
        .mini-btn { width: 40px; height: 40px; border-radius: 50%; }
    </style>
</head>
<body>
<div class="admin-shell">
    <div class="glass-card p-4 p-lg-5">
        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
            <div>
                <h1 class="h3 fw-bold mb-1 text-dark"><i class="bi bi-database-gear me-2"></i>Build Database Catasto Italia</h1>
                <p class="text-muted mb-0">Download bulk AdE, import SQLite locale e monitoraggio real-time delle 110 province.</p>
            </div>
            <span class="badge text-bg-warning fs-6 px-3 py-2">File admin temporaneo</span>
        </div>

        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="glass-card stats-card">
                    <div class="text-muted text-uppercase small fw-semibold">Database size</div>
                    <div id="stat-db-size" class="stats-value">0 MB</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="glass-card stats-card">
                    <div class="text-muted text-uppercase small fw-semibold">Particelle totali</div>
                    <div id="stat-total" class="stats-value">0</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="glass-card stats-card">
                    <div class="text-muted text-uppercase small fw-semibold">Province importate</div>
                    <div id="stat-province" class="stats-value">0 / 110</div>
                </div>
            </div>
        </div>

        <div class="glass-card p-4 mb-4">
            <div class="d-flex flex-column flex-xl-row gap-3 justify-content-between align-items-xl-center">
                <div>
                    <h2 class="h5 mb-1">Controlli build</h2>
                    <div id="build-status-text" class="text-muted">Pronto per avviare il build completo o singole province.</div>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <button id="btn-start" class="btn btn-success control-btn">
                        <i class="bi bi-play-fill me-1"></i>Avvia Build Completo
                    </button>
                    <button id="btn-pause" class="btn btn-warning control-btn" disabled>
                        <i class="bi bi-pause-fill me-1"></i>Pausa
                    </button>
                    <button id="btn-clear" class="btn btn-outline-danger control-btn">
                        <i class="bi bi-trash3 me-1"></i>Cancella Database
                    </button>
                </div>
            </div>

            <div class="mt-4">
                <div class="d-flex justify-content-between mb-2 small text-muted">
                    <span id="progress-label">0 / 110 province completate</span>
                    <span id="progress-eta">ETA: --</span>
                </div>
                <div class="progress">
                    <div id="total-progress-bar" class="progress-bar progress-bar-striped progress-bar-animated" style="width:0%">0%</div>
                </div>
            </div>
        </div>

        <div class="glass-card p-4 mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h5 mb-0">Province</h2>
                <span class="text-muted small">Play = build singola provincia</span>
            </div>
            <div id="province-list" class="province-list"></div>
        </div>

        <div class="alert alert-warning footer-warning mb-0">
            <i class="bi bi-exclamation-triangle-fill me-2"></i>
            <strong>Importante:</strong> Eliminare questo file dopo il build!
        </div>
    </div>
</div>

<script>
const adminToken = new URLSearchParams(window.location.search).get('token') || '';
const provinces = <?php echo json_encode($province, JSON_UNESCAPED_UNICODE); ?>;
const provinceState = Object.fromEntries(provinces.map(provincia => [provincia, {
    status: 'waiting',
    message: 'In attesa',
    imported: 0,
    duration: 0
}]));

let buildQueue = [];
let queueRunning = false;
let queuePaused = false;
let currentProvince = null;
let completedDurations = [];

const provinceListEl = document.getElementById('province-list');
const startBtn = document.getElementById('btn-start');
const pauseBtn = document.getElementById('btn-pause');
const clearBtn = document.getElementById('btn-clear');

startBtn.addEventListener('click', startBuildAll);
pauseBtn.addEventListener('click', togglePause);
clearBtn.addEventListener('click', clearDatabase);

renderProvinceList();
loadStats();
updateProgress();

function renderProvinceList() {
    provinceListEl.innerHTML = provinces.map(provincia => {
        const state = provinceState[provincia];
        const icon = getStatusIcon(state.status);
        const disabled = state.status === 'processing' || (queueRunning && currentProvince && currentProvince !== provincia);

        return `
            <div class="province-item ${state.status}">
                <div>
                    <div class="province-name">${icon} ${provincia}</div>
                    <div class="province-meta">${escapeHtml(state.message)}</div>
                </div>
                <button class="btn btn-outline-primary mini-btn" onclick="buildSingleProvince('${provincia}')" ${disabled ? 'disabled' : ''} title="Build singola provincia">
                    <i class="bi bi-play-fill"></i>
                </button>
            </div>
        `;
    }).join('');
}

function getStatusIcon(status) {
    switch (status) {
        case 'processing':
            return '<i class="bi bi-arrow-repeat text-warning spinner-border spinner-border-sm" style="border-width:.16em;"></i>';
        case 'success':
            return '<i class="bi bi-check-circle-fill text-success"></i>';
        case 'error':
            return '<i class="bi bi-x-circle-fill text-danger"></i>';
        default:
            return '<i class="bi bi-clock text-secondary"></i>';
    }
}

async function startBuildAll() {
    if (queueRunning) {
        return;
    }

    buildQueue = provinces.filter(provincia => provinceState[provincia].status !== 'success');
    if (!buildQueue.length) {
        document.getElementById('build-status-text').textContent = 'Tutte le province risultano già completate in questa sessione.';
        return;
    }

    queueRunning = true;
    queuePaused = false;
    pauseBtn.disabled = false;
    pauseBtn.innerHTML = '<i class="bi bi-pause-fill me-1"></i>Pausa';
    document.getElementById('build-status-text').textContent = `Build completo avviato (${buildQueue.length} province in coda)`;
    renderProvinceList();
    await processBuildQueue();
}

async function processBuildQueue() {
    while (buildQueue.length) {
        if (queuePaused) {
            queueRunning = false;
            document.getElementById('build-status-text').textContent = 'Build in pausa.';
            renderProvinceList();
            return;
        }

        const provincia = buildQueue.shift();
        await executeProvinceBuild(provincia);
    }

    queueRunning = false;
    currentProvince = null;
    pauseBtn.disabled = true;
    pauseBtn.innerHTML = '<i class="bi bi-pause-fill me-1"></i>Pausa';
    document.getElementById('build-status-text').textContent = 'Build completo terminato.';
    renderProvinceList();
    updateProgress();
}

async function buildSingleProvince(provincia) {
    if (provinceState[provincia].status === 'processing') {
        return;
    }

    if (queueRunning && currentProvince && currentProvince !== provincia) {
        return;
    }

    await executeProvinceBuild(provincia);
}

async function executeProvinceBuild(provincia) {
    currentProvince = provincia;
    provinceState[provincia] = {
        ...provinceState[provincia],
        status: 'processing',
        message: 'Download e import in corso...'
    };
    document.getElementById('build-status-text').textContent = `Processo attivo: ${provincia}`;
    renderProvinceList();
    updateProgress();

    try {
        const startedAt = performance.now();
        const response = await fetch(buildApiUrl('build', { provincia }), {
            method: 'POST'
        });
        const data = await response.json();

        if (!response.ok || !data.ok) {
            throw new Error(data.error || 'Build fallito');
        }

        const duration = Number(data.duration_sec || (Math.round(((performance.now() - startedAt) / 1000) * 100) / 100));
        completedDurations.push(duration);
        provinceState[provincia] = {
            status: 'success',
            message: `✓ ${formatNumber(data.particelle_imported || 0)} particelle importate in ${duration.toFixed(1)} sec`,
            imported: Number(data.particelle_imported || 0),
            duration
        };
        await loadStats();
    } catch (error) {
        provinceState[provincia] = {
            ...provinceState[provincia],
            status: 'error',
            message: `Errore: ${error.message}`
        };
    } finally {
        currentProvince = null;
        renderProvinceList();
        updateProgress();
    }
}

function togglePause() {
    if (pauseBtn.disabled) {
        return;
    }

    queuePaused = !queuePaused;
    pauseBtn.innerHTML = queuePaused
        ? '<i class="bi bi-play-fill me-1"></i>Riprendi'
        : '<i class="bi bi-pause-fill me-1"></i>Pausa';

    if (!queuePaused && !queueRunning && buildQueue.length) {
        queueRunning = true;
        processBuildQueue();
    }
}

async function loadStats() {
    const response = await fetch(buildApiUrl('stats'));
    const data = await response.json();

    document.getElementById('stat-db-size').textContent = `${Number(data.db_size_mb || 0).toFixed(2)} MB`;
    document.getElementById('stat-total').textContent = formatNumber(data.total_particelle || 0);
    document.getElementById('stat-province').textContent = `${formatNumber(data.province_count || 0)} / ${provinces.length}`;
}

async function clearDatabase() {
    if (!confirm('Eliminare database SQLite e download temporanei?')) {
        return;
    }

    queueRunning = false;
    queuePaused = false;
    buildQueue = [];
    currentProvince = null;
    completedDurations = [];

    const response = await fetch(buildApiUrl('clear'), { method: 'POST' });
    const data = await response.json();
    if (!response.ok || !data.ok) {
        alert(data.error || 'Impossibile cancellare il database');
        return;
    }

    provinces.forEach(provincia => {
        provinceState[provincia] = { status: 'waiting', message: 'In attesa', imported: 0, duration: 0 };
    });

    pauseBtn.disabled = true;
    pauseBtn.innerHTML = '<i class="bi bi-pause-fill me-1"></i>Pausa';
    document.getElementById('build-status-text').textContent = 'Database eliminato. Puoi riavviare il build.';
    renderProvinceList();
    updateProgress();
    await loadStats();
}

function updateProgress() {
    const completed = provinces.filter(provincia => provinceState[provincia].status === 'success').length;
    const errors = provinces.filter(provincia => provinceState[provincia].status === 'error').length;
    const percent = ((completed / provinces.length) * 100);
    const remaining = provinces.length - completed;
    const avgDuration = completedDurations.length
        ? completedDurations.reduce((sum, value) => sum + value, 0) / completedDurations.length
        : 0;
    const etaSeconds = avgDuration > 0 ? Math.round(avgDuration * remaining) : 0;

    const bar = document.getElementById('total-progress-bar');
    bar.style.width = `${percent.toFixed(1)}%`;
    bar.textContent = `${percent.toFixed(1)}%`;

    document.getElementById('progress-label').textContent = `${completed} / ${provinces.length} province completate${errors ? ` · ${errors} errori` : ''}`;
    document.getElementById('progress-eta').textContent = etaSeconds > 0 ? `ETA: ${formatEta(etaSeconds)}` : 'ETA: --';
}

function buildApiUrl(action, extraParams = {}) {
    const params = new URLSearchParams({ action });
    if (adminToken) params.set('token', adminToken);
    Object.entries(extraParams).forEach(([key, value]) => params.set(key, value));
    return `api/build_catasto_provincia.php?${params.toString()}`;
}

function formatEta(totalSeconds) {
    const hours = Math.floor(totalSeconds / 3600);
    const minutes = Math.floor((totalSeconds % 3600) / 60);
    const seconds = totalSeconds % 60;

    if (hours > 0) return `${hours}h ${minutes}m`;
    if (minutes > 0) return `${minutes}m ${seconds}s`;
    return `${seconds}s`;
}

function formatNumber(value) {
    return new Intl.NumberFormat('it-IT').format(Number(value || 0));
}

function escapeHtml(value) {
    return String(value ?? '')
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
}
</script>
</body>
</html>
