<?php

declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/layout.php';
require __DIR__ . '/_admin_check.php';

$pdo = analyticspro_db();

$totalUsers     = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role != 'admin'")->fetchColumn();
$pendingCount   = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'pending'")->fetchColumn();
$activeUsers    = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active' AND role = 'user'")->fetchColumn();
$activeSubusers = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active' AND role = 'subuser'")->fetchColumn();
$runningJobs    = (int) $pdo->query("SELECT COUNT(*) FROM ade_import_jobs WHERE status IN ('queued','extracting','importing','verifying')")->fetchColumn();

analyticspro_render_header('Amministrazione');
require __DIR__ . '/_admin_subnav.php';
?>
<h1 class="h3 mb-4">Panoramica amministrazione</h1>

<div class="row g-3 mb-4">
    <div class="col-6 col-xl-3">
        <div class="card metric-card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Utenti totali</div>
                <div class="display-6"><?= $totalUsers ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card metric-card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Registrazioni pendenti</div>
                <div class="display-6"><?= $pendingCount ?></div>
                <?php if ($pendingCount > 0): ?>
                    <a href="<?= analyticspro_h(analyticspro_base_url('admin/registrazioni.php')) ?>" class="btn btn-warning btn-sm mt-2">Gestisci</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card metric-card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Tenant attivi</div>
                <div class="display-6"><?= $activeUsers ?></div>
            </div>
        </div>
    </div>
    <div class="col-6 col-xl-3">
        <div class="card metric-card border-0 shadow-sm">
            <div class="card-body">
                <div class="text-muted small">Job ADE in corso</div>
                <div class="display-6"><?= $runningJobs ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <?php
    $quickLinks = [
        ['url' => 'admin/registrazioni.php', 'icon' => 'bi-person-check',  'title' => 'Registrazioni', 'desc' => 'Approva o rifiuta nuove richieste'],
        ['url' => 'admin/utenti.php',         'icon' => 'bi-people',         'title' => 'Utenti',         'desc' => 'Gestisci stato e permessi'],
        ['url' => 'admin/smtp.php',           'icon' => 'bi-envelope-gear',  'title' => 'SMTP',           'desc' => 'Configura il server mail'],
//        ['url' => 'admin/import_ade.php',     'icon' => 'bi-cloud-download', 'title' => 'Import ADE',     'desc' => 'Importa cartografia ADE (ZIP)'],
        ['url' => 'admin/import_gml.php',     'icon' => 'bi-map',            'title' => 'Import GML',     'desc' => 'Repository locale file GML ADE'],
    ];
    foreach ($quickLinks as $link): ?>
        <div class="col-sm-6 col-lg-3">
            <a href="<?= analyticspro_h(analyticspro_base_url($link['url'])) ?>" class="card border-0 shadow-sm text-decoration-none text-dark h-100">
                <div class="card-body d-flex align-items-center gap-3">
                    <i class="bi <?= analyticspro_h($link['icon']) ?> fs-2" style="color:var(--ap-blue)"></i>
                    <div>
                        <div class="fw-semibold"><?= analyticspro_h($link['title']) ?></div>
                        <div class="text-muted small"><?= analyticspro_h($link['desc']) ?></div>
                    </div>
                </div>
            </a>
        </div>
    <?php endforeach; ?>
</div>
<?php analyticspro_render_footer(); ?>
