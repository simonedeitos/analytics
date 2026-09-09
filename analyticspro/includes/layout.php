<?php

declare(strict_types=1);

require_once __DIR__ . '/navigation.php';

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
 * the active sidebar item. Works both from the analyticspro/ root and from
 * sub-directories (admin/).
 */
function analyticspro_current_page(): string
{
    $script = $_SERVER['SCRIPT_FILENAME'] ?? '';
    $base   = basename(dirname($script));
    $file   = basename($script);
    if ($base === 'analyticspro') {
        return $file;
    }
    return $base . '/' . $file;
}

function analyticspro_asset_url(string $relative): string
{
    $fsPath = ANALYTICSPRO_ROOT . '/' . ltrim($relative, '/');
    $version = is_file($fsPath) ? (string) filemtime($fsPath) : '1';
    return analyticspro_base_url($relative) . '?v=' . $version;
}

function analyticspro_layout_role_label(array $user): string
{
    return match ((string) ($user['role'] ?? '')) {
        'admin' => 'Admin',
        'subuser' => 'Subutente',
        default => 'Utente',
    };
}

function analyticspro_layout_user_initials(array $user): string
{
    $fullName = trim((string) ($user['nome'] ?? '') . ' ' . (string) ($user['cognome'] ?? ''));
    if ($fullName === '') {
        return 'AP';
    }

    $parts = preg_split('/\s+/', $fullName) ?: [];
    $initials = '';
    foreach (array_slice($parts, 0, 2) as $part) {
        $initials .= mb_strtoupper(mb_substr($part, 0, 1, 'UTF-8'), 'UTF-8');
    }
    return $initials !== '' ? $initials : 'AP';
}

function analyticspro_render_header(string $title, array $options = []): void
{
    $user             = analyticspro_current_user();
    $includeAppAssets = $options['app_assets'] ?? false;
    $bodyClass        = trim((string) ($options['body_class'] ?? ''));
    $topbarContent    = (string) ($options['topbar_content'] ?? '');
    $topbarClass      = trim((string) ($options['topbar_class'] ?? ''));
    $flash            = analyticspro_take_flash();
    $isAuth           = $options['auth_page'] ?? false;
    analyticspro_layout_set_auth_page($isAuth);

    $subuserPermissions = ($user && analyticspro_is_subuser())
        ? analyticspro_get_subuser_permissions((int) $user['id'])
        : [];
    $currentPage = analyticspro_current_page();
    $navSections = $user ? analyticspro_nav_items($user, $subuserPermissions) : [];
    $selectedTenant = $user && analyticspro_is_admin() ? (string) analyticspro_get('tenant_id', 'all') : '';
    ?>
    <!DOCTYPE html>
    <html lang="it">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="csrf-token" content="<?= analyticspro_h(analyticspro_csrf_token()) ?>">
        <title><?= analyticspro_h($title) ?> - AnalyticsPRO</title>
        <link rel="icon" href="<?= analyticspro_h(analyticspro_base_url('favicon.php?v=2')) ?>" sizes="any">
        <link rel="shortcut icon" href="<?= analyticspro_h(analyticspro_base_url('favicon.php?v=2')) ?>">
        <link rel="apple-touch-icon" href="<?= analyticspro_h(analyticspro_base_url('favicon.php?v=2')) ?>">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <script>
        (function () {
            try {
                var theme = window.localStorage.getItem('analyticspro:theme') || 'light';
                document.documentElement.setAttribute('data-theme', theme);
            } catch (error) {
                document.documentElement.setAttribute('data-theme', 'light');
            }
        })();
        </script>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
        <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
        <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.bootstrap5.min.css">
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
        <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css">
        <link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css">
        <link rel="stylesheet" href="<?= analyticspro_h(analyticspro_asset_url('assets/css/tokens.css')) ?>">
        <link rel="stylesheet" href="<?= analyticspro_h(analyticspro_asset_url('assets/css/app.css')) ?>">
        <link rel="stylesheet" href="<?= analyticspro_h(analyticspro_asset_url('assets/css/layout.css')) ?>">
        <link rel="stylesheet" href="<?= analyticspro_h(analyticspro_asset_url('assets/css/components.css')) ?>">
        <link rel="stylesheet" href="<?= analyticspro_h(analyticspro_asset_url('assets/css/pages.css')) ?>">
        <?php if (!empty($options['extra_head'])) echo $options['extra_head']; ?>
    </head>
    <body class="<?= analyticspro_h($bodyClass) ?>">

    <?php if ($isAuth || !$user): ?>
        <main class="ap-auth-shell">
            <?php foreach ($flash as $message): ?>
                <div class="alert alert-<?= analyticspro_h($message['type']) ?> alert-dismissible fade show m-3" role="alert">
                    <?= analyticspro_h($message['message']) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endforeach; ?>
    <?php else: ?>
        <aside class="ap-sidebar" id="apSidebar" aria-label="Menu principale">
            <div class="ap-sidebar-inner">
                <a class="ap-sidebar-brand" href="<?= analyticspro_h(analyticspro_base_url('dashboard.php')) ?>">
                    <span class="ap-sidebar-brand-mark"><i class="bi bi-graph-up-arrow"></i></span>
                    <span class="ap-sidebar-brand-text">
                        <strong>AnalyticsPRO</strong>
                        <small>Immobili &amp; territorio</small>
                    </span>
                </a>

                <div class="ap-sidebar-search-wrap">
                    <label class="visually-hidden" for="apSidebarSearch">Cerca nel menu</label>
                    <div class="ap-sidebar-search">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input type="search" id="apSidebarSearch" class="form-control" placeholder="Cerca nel menu...">
                    </div>
                </div>

                <nav class="ap-sidebar-nav">
                    <?php foreach ($navSections as $section): ?>
                        <?php
                        $sectionVisible = $section['visible'] ?? true;
                        if (!$sectionVisible) {
                            continue;
                        }
                        $items = [];
                        foreach ($section['items'] as $item) {
                            if (!($item['visible'] ?? true)) {
                                continue;
                            }
                            $items[] = $item;
                        }
                        if ($items === []) {
                            continue;
                        }
                        ?>
                        <div class="ap-nav-section" data-nav-section>
                            <div class="ap-nav-section-label"><?= analyticspro_h((string) $section['label']) ?></div>
                            <ul class="list-unstyled mb-0">
                                <?php foreach ($items as $item): ?>
                                    <?php $active = analyticspro_page_matches($currentPage, (array) ($item['page'] ?? [])); ?>
                                    <li data-nav-item data-nav-label="<?= analyticspro_h(mb_strtolower((string) $item['label'], 'UTF-8')) ?>">
                                        <a href="<?= analyticspro_h((string) $item['url']) ?>"
                                           class="ap-nav-link<?= $active ? ' active' : '' ?>"
                                           <?= $active ? ' aria-current="page"' : '' ?>
                                           data-bs-toggle="tooltip"
                                           data-bs-placement="right"
                                           title="<?= analyticspro_h((string) $item['label']) ?>">
                                            <span class="ap-nav-link-icon"><i class="bi <?= analyticspro_h((string) $item['icon']) ?>"></i></span>
                                            <span class="ap-nav-link-text"><?= analyticspro_h((string) $item['label']) ?></span>
                                            <?php if ((int) ($item['badge'] ?? 0) > 0): ?>
                                                <span class="badge rounded-pill text-bg-danger ms-auto ap-nav-badge"><?= (int) $item['badge'] ?></span>
                                            <?php endif; ?>
                                        </a>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endforeach; ?>
                </nav>
            </div>

            <div class="ap-sidebar-user">
                <div class="dropdown">
                    <button class="ap-sidebar-user-toggle dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="ap-avatar"><?= analyticspro_h(analyticspro_layout_user_initials($user)) ?></span>
                        <span class="ap-sidebar-user-text">
                            <strong><?= analyticspro_h(analyticspro_full_name($user)) ?></strong>
                            <small><?= analyticspro_h(analyticspro_layout_role_label($user)) ?></small>
                        </span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm ap-sidebar-user-menu">
                        <?php if (analyticspro_is_subuser() && !empty($user['must_change_password'])): ?>
                            <li><a class="dropdown-item" href="<?= analyticspro_h(analyticspro_base_url('change_password.php')) ?>"><i class="bi bi-key me-2"></i>Cambia password</a></li>
                        <?php else: ?>
                            <li><a class="dropdown-item" href="<?= analyticspro_h(analyticspro_base_url('change_password.php')) ?>"><i class="bi bi-key me-2"></i>Cambia password</a></li>
                        <?php endif; ?>
                        <li><a class="dropdown-item" href="<?= analyticspro_h(analyticspro_base_url('aiuto.php')) ?>"><i class="bi bi-question-circle me-2"></i>Aiuto</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="<?= analyticspro_h(analyticspro_base_url('logout.php')) ?>"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </aside>

        <div class="ap-sidebar-overlay" id="apOverlay"></div>

        <header class="ap-topbar<?= $topbarClass !== '' ? ' ' . analyticspro_h($topbarClass) : '' ?>">
            <div class="ap-topbar-start">
                <button class="ap-hamburger" id="apHamburger" aria-label="Apri menu">
                    <i class="bi bi-list"></i>
                </button>
                <button class="ap-sidebar-collapse-toggle d-none d-lg-inline-flex" id="apSidebarCollapseToggle" aria-label="Comprimi barra laterale" aria-pressed="false">
                    <i class="bi bi-layout-sidebar-inset"></i>
                </button>
                <div class="ap-topbar-heading">
                    <span class="ap-topbar-breadcrumb">AnalyticsPRO</span>
                    <span class="ap-topbar-title"><?= analyticspro_h($title) ?></span>
                </div>
            </div>
            <div class="ap-topbar-middle">
                <form method="get" action="<?= analyticspro_h(analyticspro_base_url('report.php')) ?>" class="ap-topbar-search" role="search">
                    <?php if ($selectedTenant !== ''): ?>
                        <input type="hidden" name="tenant_id" value="<?= analyticspro_h($selectedTenant) ?>">
                    <?php endif; ?>
                    <label class="visually-hidden" for="apGlobalSearch">Ricerca globale</label>
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input id="apGlobalSearch" type="search" name="q" value="<?= analyticspro_h((string) analyticspro_get('q', '')) ?>" class="form-control" placeholder="Cerca comune, indirizzo, intestatario...">
                </form>
            </div>
            <div class="ap-topbar-end">
                <?php if ($topbarContent !== ''): ?>
                    <div class="ap-topbar-slot"><?= $topbarContent ?></div>
                <?php endif; ?>
                <button class="ap-theme-toggle" id="apThemeToggle" type="button" aria-label="Cambia tema" aria-pressed="false">
                    <i class="bi bi-moon-stars"></i>
                    <span class="d-none d-sm-inline">Tema</span>
                </button>
            </div>
        </header>

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
            </div>
        </div>
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
        <script src="<?= analyticspro_h(analyticspro_asset_url('assets/js/chart-theme.js')) ?>"></script>
        <script src="<?= analyticspro_h(analyticspro_asset_url('assets/js/analyticspro.js')) ?>"></script>
    <?php endif; ?>
    <?php if ($user): ?>
    <script>
    (function () {
        var root = document.documentElement;
        var body = document.body;
        var sidebar = document.getElementById('apSidebar');
        var overlay = document.getElementById('apOverlay');
        var hamburger = document.getElementById('apHamburger');
        var collapseToggle = document.getElementById('apSidebarCollapseToggle');
        var topbar = document.querySelector('.ap-topbar');
        var themeToggle = document.getElementById('apThemeToggle');
        var sidebarSearch = document.getElementById('apSidebarSearch');
        var storagePrefix = 'analyticspro:';
        var collapseKey = storagePrefix + 'sidebar-collapsed';
        var themeKey = storagePrefix + 'theme';
        var lastHeight = 0;

        function readFlag(key) {
            try { return window.localStorage.getItem(key) === '1'; } catch (error) { return false; }
        }

        function writeFlag(key, value) {
            try { window.localStorage.setItem(key, value ? '1' : '0'); } catch (error) {}
        }

        function dispatchLayoutResize() {
            window.dispatchEvent(new CustomEvent('analyticspro:layout-resize'));
            if (topbar) {
                syncTopbarOffset();
            }
        }

        function setSidebarCollapsed(collapsed) {
            body.classList.toggle('ap-sidebar-collapsed', collapsed);
            if (collapseToggle) {
                collapseToggle.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
                collapseToggle.setAttribute('aria-label', collapsed ? 'Espandi barra laterale' : 'Comprimi barra laterale');
                collapseToggle.innerHTML = collapsed
                    ? '<i class="bi bi-layout-sidebar-inset-reverse"></i>'
                    : '<i class="bi bi-layout-sidebar-inset"></i>';
            }
            writeFlag(collapseKey, collapsed);
            window.setTimeout(dispatchLayoutResize, 260);
        }

        function openSidebar() {
            if (!sidebar || !overlay) return;
            sidebar.classList.add('open');
            overlay.classList.add('show');
        }

        function closeSidebar() {
            if (!sidebar || !overlay) return;
            sidebar.classList.remove('open');
            overlay.classList.remove('show');
        }

        function syncTopbarOffset() {
            if (!topbar) return;
            var nextHeight = Math.max(0, Math.ceil(topbar.getBoundingClientRect().height || 0));
            if (!nextHeight) return;
            root.style.setProperty('--ap-topbar-offset', nextHeight + 'px');
            if (nextHeight !== lastHeight) {
                lastHeight = nextHeight;
                window.dispatchEvent(new CustomEvent('analyticspro:topbar-resize', { detail: { height: nextHeight } }));
            }
        }

        function applyTheme(theme) {
            root.setAttribute('data-theme', theme);
            try { window.localStorage.setItem(themeKey, theme); } catch (error) {}
            if (themeToggle) {
                themeToggle.setAttribute('aria-pressed', theme === 'dark' ? 'true' : 'false');
                themeToggle.innerHTML = theme === 'dark'
                    ? '<i class="bi bi-sunrise"></i><span class="d-none d-sm-inline">Chiaro</span>'
                    : '<i class="bi bi-moon-stars"></i><span class="d-none d-sm-inline">Scuro</span>';
            }
            window.dispatchEvent(new CustomEvent('analyticspro:theme-change', { detail: { theme: theme } }));
        }

        function filterSidebar(query) {
            var q = String(query || '').trim().toLowerCase();
            document.querySelectorAll('[data-nav-section]').forEach(function (section) {
                var visibleCount = 0;
                section.querySelectorAll('[data-nav-item]').forEach(function (item) {
                    var label = item.getAttribute('data-nav-label') || '';
                    var visible = q === '' || label.indexOf(q) !== -1;
                    item.classList.toggle('d-none', !visible);
                    if (visible) visibleCount += 1;
                });
                section.classList.toggle('d-none', visibleCount === 0);
            });
        }

        if (hamburger) {
            hamburger.addEventListener('click', function () {
                if (sidebar && sidebar.classList.contains('open')) {
                    closeSidebar();
                } else {
                    openSidebar();
                }
            });
        }
        if (overlay) {
            overlay.addEventListener('click', closeSidebar);
        }
        if (collapseToggle) {
            collapseToggle.addEventListener('click', function () {
                setSidebarCollapsed(!body.classList.contains('ap-sidebar-collapsed'));
            });
        }
        if (sidebarSearch) {
            sidebarSearch.addEventListener('input', function () {
                filterSidebar(sidebarSearch.value);
            });
        }
        if (themeToggle) {
            themeToggle.addEventListener('click', function () {
                applyTheme(root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark');
            });
        }
        if (topbar) {
            document.addEventListener('scroll', function () {
                topbar.classList.toggle('is-scrolled', window.scrollY > 8);
            }, { passive: true });
        }

        var tooltips = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltips.forEach(function (el) { bootstrap.Tooltip.getOrCreateInstance(el); });

        if (window.matchMedia('(min-width: 992px)').matches) {
            setSidebarCollapsed(readFlag(collapseKey));
        }
        applyTheme(root.getAttribute('data-theme') || 'light');
        syncTopbarOffset();
        window.addEventListener('load', syncTopbarOffset);
        window.addEventListener('resize', function () {
            syncTopbarOffset();
            if (window.matchMedia('(max-width: 991.98px)').matches) {
                closeSidebar();
            }
        });
        if (document.fonts && document.fonts.ready && typeof document.fonts.ready.then === 'function') {
            document.fonts.ready.then(syncTopbarOffset).catch(function () {});
        }
    })();
    </script>
    <?php endif; ?>
    </body>
    </html>
    <?php
}
