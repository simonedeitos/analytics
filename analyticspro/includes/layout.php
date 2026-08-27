<?php

declare(strict_types=1);

function analyticspro_render_header(string $title, array $options = []): void
{
    $user = analyticspro_current_user();
    $includeAppAssets = $options['app_assets'] ?? false;
    $bodyClass = $options['body_class'] ?? 'bg-light';
    $flash = analyticspro_take_flash();
    ?>
    <!DOCTYPE html>
    <html lang="it">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="csrf-token" content="<?= analyticspro_h(analyticspro_csrf_token()) ?>">
        <title><?= analyticspro_h($title) ?> - AnalyticsPRO</title>
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
        <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
        <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
        <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">
        <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css">
        <link rel="stylesheet" href="<?= analyticspro_h(analyticspro_base_url('assets/css/app.css')) ?>">
    </head>
    <body class="<?= analyticspro_h($bodyClass) ?>">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary shadow-sm">
        <div class="container-fluid px-4">
            <a class="navbar-brand fw-bold" href="<?= analyticspro_h(analyticspro_base_url('dashboard.php')) ?>">
                <i class="bi bi-graph-up-arrow me-2"></i>AnalyticsPRO
            </a>
            <?php if ($user): ?>
                <div class="d-flex align-items-center gap-2 ms-auto">
                    <span class="navbar-text small text-white-50"><?= analyticspro_h(analyticspro_full_name($user)) ?> · <?= analyticspro_h($user['role']) ?></span>
                    <a class="btn btn-sm btn-outline-light" href="<?= analyticspro_h(analyticspro_base_url('aiuto.php')) ?>">Aiuto</a>
                    <a class="btn btn-sm btn-light" href="<?= analyticspro_h(analyticspro_base_url('logout.php')) ?>">Logout</a>
                </div>
            <?php endif; ?>
        </div>
    </nav>
    <main class="container-fluid px-3 px-md-4 py-4">
        <?php foreach ($flash as $message): ?>
            <div class="alert alert-<?= analyticspro_h($message['type']) ?> alert-dismissible fade show" role="alert">
                <?= analyticspro_h($message['message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endforeach; ?>
    <?php
}

function analyticspro_render_footer(bool $includeAppAssets = false): void
{
    ?>
    </main>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <script src="https://cdn.sheetjs.com/xlsx-0.20.3/package/dist/xlsx.full.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
    <?php if ($includeAppAssets): ?>
        <script src="<?= analyticspro_h(analyticspro_base_url('assets/js/analyticspro.js')) ?>"></script>
    <?php endif; ?>
    </body>
    </html>
    <?php
}
