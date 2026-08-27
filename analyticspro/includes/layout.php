<?php

declare(strict_types=1);

/**
 * Stores whether the header was rendered in auth-page mode (no sidebar).
 * Set by analyticspro_render_header() so the footer can match the same layout branch.
 */
function analyticspro_layout_set_auth_page(bool $value): void
{
    $_SERVER['__ap_auth_page'] = $value ? '1' : '0';
}

function analyticspro_layout_check_auth_page(): bool
{
    return ($_SERVER['__ap_auth_page'] ?? '0') === '1';
}

/**
 * Returns the current page filename (e.g. "dashboard.php") used to highlight
 * the active sidebar item.  Works both from the analyticspro/ root and from
 * sub-directories (admin/).
 */
function analyticspro_current_page(): string
{
    $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
    // Normalise: use the last two path segments: "admin/utenti.php" or "dashboard.php"
    $base   = basename(dirname($script));
    $file   = basename($script);
    if ($base === 'analyticspro') {
        return $file;
    }
    return $base . '/' . $file;
}

function analyticspro_render_header(string $title, array $options = []): void
{
    $user            = analyticspro_current_user();
    $includeAppAssets = $options['app_assets'] ?? false;
    $bodyClass       = $options['body_class'] ?? '';
    $flash           = analyticspro_take_flash();
    $isAuth          = $options['auth_page'] ?? false;   // login/register – no sidebar
    analyticspro_layout_set_auth_page($isAuth);

    // Subuser permissions (used by sidebar)
    $subuserPermissions = ($user && analyticspro_is_subuser())
        ? analyticspro_get_subuser_permissions((int) $user['id'])
        : null;

    $currentPage = analyticspro_current_page();

    // Helper: mark a sidebar link active
    $isActive = static function (string $page) use ($currentPage): string {
        return $currentPage === $page ? ' active' : '';
    };
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
        <?php if (!empty($options['extra_head'])) echo $options['extra_head']; ?>
    </head>
    <body class="<?= analyticspro_h($bodyClass) ?>">

    <?php if ($isAuth || !$user): ?>
        <!-- Auth pages: plain body, no sidebar -->
        <main>
            <?php foreach ($flash as $message): ?>
                <div class="alert alert-<?= analyticspro_h($message['type']) ?> alert-dismissible fade show m-3" role="alert">
                    <?= analyticspro_h($message['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endforeach; ?>
    <?php else: ?>
        <!-- ===== SIDEBAR ===== -->
        <aside class="ap-sidebar" id="apSidebar" aria-label="Menu principale">
            <a class="ap-sidebar-brand" href="<?= analyticspro_h(analyticspro_base_url('dashboard.php')) ?>">
                <i class="bi bi-graph-up-arrow brand-icon"></i>
                <span>AnalyticsPRO</span>
            </a>

            <nav>
                <div class="ap-nav-section-label">Dati</div>
                <ul>
                    <li>
                        <a href="<?= analyticspro_h(analyticspro_base_url('dashboard.php')) ?>"
                           class="<?= $isActive('dashboard.php') ?>">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </li>
                    <?php if (!analyticspro_is_subuser() || !empty($subuserPermissions['can_import'])): ?>
                    <li>
                        <a href="<?= analyticspro_h(analyticspro_base_url('importa.php')) ?>"
                           class="<?= $isActive('importa.php') ?>">
                            <i class="bi bi-upload"></i> Importa dati
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>

                <div class="ap-nav-section-label">Mappa</div>
                <ul>
                    <li>
                        <a href="<?= analyticspro_h(analyticspro_base_url('mappa.php')) ?>"
                           class="<?= $isActive('mappa.php') ?>">
                            <i class="bi bi-map"></i> Mappa
                        </a>
                    </li>
                    <li>
                        <a href="<?= analyticspro_h(analyticspro_base_url('assegnati.php')) ?>"
                           class="<?= $isActive('assegnati.php') ?>">
                            <i class="bi bi-pin-map"></i> Marker assegnati
                        </a>
                    </li>
                </ul>

                <?php if (!analyticspro_is_subuser() || !empty($subuserPermissions['can_view_reports']) || !empty($subuserPermissions['can_view_analytics'])): ?>
                <div class="ap-nav-section-label">Report</div>
                <ul>
                    <?php if (!analyticspro_is_subuser() || !empty($subuserPermissions['can_view_reports'])): ?>
                    <li>
                        <a href="<?= analyticspro_h(analyticspro_base_url('report.php')) ?>"
                           class="<?= $isActive('report.php') ?>">
                            <i class="bi bi-table"></i> Report in griglia
                        </a>
                    </li>
                    <?php endif; ?>
                    <?php if (!analyticspro_is_subuser() || !empty($subuserPermissions['can_view_analytics'])): ?>
                    <li>
                        <a href="<?= analyticspro_h(analyticspro_base_url('analitiche.php')) ?>"
                           class="<?= $isActive('analitiche.php') ?>">
                            <i class="bi bi-bar-chart-line"></i> Analitiche
                        </a>
                    </li>
                    <?php endif; ?>
                </ul>
                <?php endif; ?>

                <div class="ap-nav-section-label">Account</div>
                <ul>
                    <?php if (analyticspro_is_main_user()): ?>
                    <li>
                        <a href="<?= analyticspro_h(analyticspro_base_url('subutenti.php')) ?>"
                           class="<?= $isActive('subutenti.php') ?>">
                            <i class="bi bi-people"></i> Subutenti
                        </a>
                    </li>
                    <?php endif; ?>
                    <li>
                        <a href="<?= analyticspro_h(analyticspro_base_url('aiuto.php')) ?>"
                           class="<?= $isActive('aiuto.php') ?>">
                            <i class="bi bi-question-circle"></i> Aiuto
                        </a>
                    </li>
                    <li>
                        <a href="<?= analyticspro_h(analyticspro_base_url('logout.php')) ?>">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </a>
                    </li>
                </ul>

                <?php if (analyticspro_is_admin()): ?>
                <?php $pendingCount = analyticspro_count_pending_registrations(); ?>
                <div class="ap-nav-section-label">Amministrazione</div>
                <ul>
                    <li>
                        <a href="<?= analyticspro_h(analyticspro_base_url('admin/index.php')) ?>"
                           class="<?= strpos($currentPage, 'admin/') === 0 ? 'active' : '' ?>">
                            <i class="bi bi-shield-check"></i> Amministrazione
                            <?php if ($pendingCount > 0): ?>
                                <span class="badge rounded-pill bg-danger ms-auto"><?= (int) $pendingCount ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                </ul>
                <?php endif; ?>
            </nav>
        </aside>

        <!-- Overlay for mobile -->
        <div class="ap-sidebar-overlay" id="apOverlay"></div>

        <!-- ===== TOP NAVBAR ===== -->
        <header class="ap-topbar">
            <button class="ap-hamburger" id="apHamburger" aria-label="Apri menu">
                <i class="bi bi-list"></i>
            </button>
            <span class="ap-topbar-title"><?= analyticspro_h($title) ?></span>
            <?php if ($user): ?>
                <span class="ap-topbar-user d-none d-md-inline">
                    <?= analyticspro_h(analyticspro_full_name($user)) ?>
                    <span class="badge ms-1" style="background:var(--ap-blue);font-size:.7rem;">
                        <?= analyticspro_h($user['role']) ?>
                    </span>
                </span>
            <?php endif; ?>
        </header>

        <!-- ===== MAIN CONTENT ===== -->
        <div class="ap-main">
            <div class="ap-content">
                <?php foreach ($flash as $message): ?>
                    <div class="alert alert-<?= analyticspro_h($message['type']) ?> alert-dismissible fade show" role="alert">
                        <?= analyticspro_h($message['message']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endforeach; ?>
    <?php endif; ?>
    <?php
}

function analyticspro_render_footer(bool $includeAppAssets = false): void
{
    $user   = analyticspro_current_user();
    $isAuth = analyticspro_layout_check_auth_page();
    ?>
    <?php if ($isAuth): ?>
        </main>
    <?php else: ?>
            </div><!-- /.ap-content -->
        </div><!-- /.ap-main -->
    <?php endif; ?>

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
    <?php if ($user): ?>
    <script>
    /* Sidebar hamburger toggle */
    (function () {
        var btn     = document.getElementById('apHamburger');
        var sidebar = document.getElementById('apSidebar');
        var overlay = document.getElementById('apOverlay');
        if (!btn || !sidebar || !overlay) return;
        function open()  { sidebar.classList.add('open');  overlay.classList.add('show'); }
        function close() { sidebar.classList.remove('open'); overlay.classList.remove('show'); }
        btn.addEventListener('click', function() { sidebar.classList.contains('open') ? close() : open(); });
        overlay.addEventListener('click', close);
    })();
    </script>
    <?php endif; ?>
    </body>
    </html>
    <?php
}
