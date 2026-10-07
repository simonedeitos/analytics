<?php

declare(strict_types=1);

define('ANALYTICSPRO_ROOT', dirname(__DIR__));

$testUser = ['id' => 1, 'role' => 'user'];
$testPermissions = [];

function analyticspro_current_user(): array { return $GLOBALS['testUser']; }
function analyticspro_is_subuser(): bool { return analyticspro_current_user()['role'] === 'subuser'; }
function analyticspro_is_admin(): bool { return analyticspro_current_user()['role'] === 'admin'; }
function analyticspro_is_main_user(): bool { return analyticspro_current_user()['role'] === 'user'; }
function analyticspro_get_subuser_permissions(int $id): array { return $GLOBALS['testPermissions']; }
function analyticspro_count_pending_registrations(): int { return 3; }
function analyticspro_base_url(string $path): string { return '/analyticspro/' . ltrim($path, '/'); }
function analyticspro_take_flash(): array { return []; }
function analyticspro_csrf_token(): string { return 'ui-test-token'; }
function analyticspro_h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function analyticspro_full_name(array $user): string { return 'UI Test'; }

require_once ANALYTICSPRO_ROOT . '/includes/layout.php';

$checks = 0;
function check_ui(bool $condition, string $message): void
{
    $GLOBALS['checks']++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function visible_ui_labels(array $sections): array
{
    $labels = [];
    foreach ($sections as $section) {
        if (!($section['visible'] ?? true)) {
            continue;
        }
        foreach ($section['items'] as $item) {
            if ($item['visible'] ?? true) {
                $labels[] = $item['label'];
            }
        }
    }
    return $labels;
}

foreach (['user', 'admin', 'subuser'] as $role) {
    foreach ([0, 1] as $canImport) {
        foreach ([0, 1] as $canReport) {
            foreach ([0, 1] as $canAnalytics) {
                $testUser['role'] = $role;
                $testPermissions = ['can_import' => $canImport, 'can_view_reports' => $canReport, 'can_view_analytics' => $canAnalytics];
                $expected = ['Panoramica', 'Mappa'];
                if ($role !== 'subuser' || $canReport) {
                    $expected[] = 'Proprietari';
                }
                $expected[] = 'Assegnati a me';
                if ($role !== 'subuser' || $canImport) {
                    $expected[] = 'Importa dati';
                }
                if ($role === 'user') {
                    $expected[] = 'Team';
                }
                $expected[] = 'Aiuto';
                if ($role === 'admin') {
                    $expected[] = 'Amministrazione';
                }
                $sections = analyticspro_nav_items($testUser, $testPermissions);
                check_ui(visible_ui_labels($sections) === $expected, "navigation order/permissions for {$role}");
                check_ui($sections[1]['label'] === 'Gestione', 'management section label');
                check_ui($sections[0]['items'][2]['page'] === ['report.php'], 'owners keep the existing report route');
                check_ui($sections[2]['items'][0]['badge'] === ($role === 'admin' ? 3 : 0), 'admin pending registration badge');
            }
        }
    }
}

$testUser['role'] = 'user';
$_SERVER['SCRIPT_FILENAME'] = ANALYTICSPRO_ROOT . '/dashboard.php';
ob_start();
analyticspro_render_header('Panoramica <test>');
analyticspro_render_footer();
$html = (string) ob_get_clean();
check_ui(str_contains($html, 'Panoramica &lt;test&gt; - easyradar'), 'page title escapes text and uses easyradar');
check_ui(!str_contains($html, 'AnalyticsPRO'), 'no old visible branding in shared shell');
check_ui(str_contains($html, 'aria-current="page"'), 'active navigation remains accessible');
check_ui(!str_contains($html, 'analitiche.php'), 'removed analytics route is not linked');
check_ui(str_contains($html, 'id="apHamburger"') && str_contains($html, 'id="apSidebarCollapseToggle"'), 'mobile/collapsed navigation hooks preserved');
check_ui(str_contains($html, 'analyticspro:layout-resize') && str_contains($html, 'analyticspro:topbar-resize'), 'technical layout event names preserved');
foreach (['tokens', 'app', 'layout', 'components', 'pages'] as $stylesheet) {
    check_ui(str_contains($html, "assets/css/{$stylesheet}.css?v="), "{$stylesheet} stylesheet uses versioned asset helper");
}
if (is_file(ANALYTICSPRO_ROOT . '/logo.png')) {
    check_ui(str_contains($html, 'logo.png?v=') && str_contains($html, 'alt="easyradar"'), 'existing public logo uses asset helper and accessible branding');
} else {
    check_ui(str_contains($html, '<strong>easyradar</strong>') && !str_contains($html, 'src="/analyticspro/logo.png'), 'missing logo falls back to text without broken image');
}
check_ui(analyticspro_layout_user_initials([]) === 'ER', 'empty initials match easyradar');
$_SERVER['SCRIPT_FILENAME'] = ANALYTICSPRO_ROOT . '/admin/utenti.php';
check_ui(analyticspro_current_page() === 'admin/utenti.php', 'nested admin active-page matching preserved');
check_ui(analyticspro_page_matches('admin/utenti.php', analyticspro_nav_items()[2]['items'][0]['page']), 'admin subpage matches admin entry');

ob_start();
analyticspro_render_header('Login', ['auth_page' => true]);
analyticspro_render_footer();
$authHtml = (string) ob_get_clean();
check_ui(str_contains($authHtml, 'ap-auth-shell') && !str_contains($authHtml, '<aside'), 'auth layout remains sidebar-free');
check_ui(str_contains($authHtml, '</main>'), 'auth layout closes its main element');

$team = (string) file_get_contents(ANALYTICSPRO_ROOT . '/subutenti.php');
check_ui(str_contains($team, 'name="action" value="invite_subuser"') && str_contains($team, 'name="action" value="update_subuser_permissions"'), 'team form actions preserved');
check_ui(substr_count($team, 'name="csrf_token"') === 2, 'both team forms preserve CSRF fields');
check_ui(str_contains($team, 'for="invite-email"') && str_contains($team, 'for="subuser-'), 'team form labels associated with controls');
check_ui(str_contains($team, 'Può vedere proprietari') && str_contains($team, "'can_view_reports' => 'Proprietari'"), 'existing report permission uses owners label without changing its key');
$help = (string) file_get_contents(ANALYTICSPRO_ROOT . '/aiuto.php');
check_ui(!str_contains($help, 'Report in griglia') && !str_contains($help, 'Marker assegnati'), 'help uses current page names');
check_ui(str_contains($help, '<strong>Team</strong> nel menu') && str_contains($help, '"Team" nel menu'), 'guide and FAQ link users to Team');
$assigned = (string) file_get_contents(ANALYTICSPRO_ROOT . '/assegnati.php');
check_ui(str_contains($assigned, "analyticspro_render_header('Assegnati a me'"), 'assigned page title matches navigation');
$dashboard = (string) file_get_contents(ANALYTICSPRO_ROOT . '/dashboard.php');
foreach (['data-dashboard-tab="all"', 'dashboard-mini-map', 'dashboard-export', 'data-dashboard-filter-control="comune"', 'data-dashboard-filter-control="category"'] as $hook) {
    check_ui(str_contains($dashboard, $hook), "dashboard hook {$hook} preserved");
}

echo "PASS: shared UI regression ({$checks} checks)\n";
