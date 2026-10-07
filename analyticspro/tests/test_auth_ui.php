<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$tmpDir = sys_get_temp_dir() . '/analyticspro_auth_ui_' . getmypid();
mkdir($tmpDir, 0700);
define('ANALYTICSPRO_ROOT', $tmpDir);
register_shutdown_function(static function () use ($tmpDir): void {
    if (is_file($tmpDir . '/logo.png')) {
        unlink($tmpDir . '/logo.png');
    }
    rmdir($tmpDir);
});

function analyticspro_current_user(): ?array { return null; }
function analyticspro_base_url(string $path): string { return '/analyticspro/' . ltrim($path, '/'); }
function analyticspro_csrf_token(): string { return 'auth-ui-test-token'; }
function analyticspro_h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }

require_once $root . '/includes/layout.php';
analyticspro_layout_set_auth_page(true);

$checks = 0;
function check_auth_ui(bool $condition, string $message): void
{
    $GLOBALS['checks']++;
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function render_auth_template(string $source): string
{
    $emailValue = 'test@example.test';
    ob_start();
    eval('?>' . substr($source, strpos($source, '?>') + 2));
    return (string) ob_get_clean();
}

foreach (['login.php' => 'Accedi a easyradar', 'change_password.php' => 'Nuova password'] as $page => $heading) {
    $source = (string) file_get_contents($root . '/' . $page);
    $withoutLogo = render_auth_template($source);
    check_auth_ui(!str_contains($withoutLogo, 'class="ap-auth-logo"'), "{$page} omits the missing logo without a broken image");
    file_put_contents($tmpDir . '/logo.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));
    clearstatcache();
    $withLogo = render_auth_template($source);
    check_auth_ui(str_contains($withLogo, 'class="ap-auth-brand ap-auth-brand-image"'), "{$page} uses the shared image treatment");
    check_auth_ui(str_contains($withLogo, 'src="/analyticspro/logo.png?v=' . filemtime($tmpDir . '/logo.png') . '"'), "{$page} versions the public logo URL");
    check_auth_ui(str_contains($withLogo, 'class="ap-auth-logo"') && str_contains($withLogo, 'alt="easyradar"'), "{$page} uses accessible shared logo styling");
    check_auth_ui(strpos($withLogo, 'class="ap-auth-logo"') < strpos($withLogo, '<h2 class="h3 mb-2">' . $heading), "{$page} places the logo above the form heading");
    check_auth_ui(str_contains($withLogo, '<form method="post">') && str_contains($withLogo, 'name="csrf_token" value="auth-ui-test-token"'), "{$page} retains the POST form and CSRF token");
    preg_match('/<form\b.*?<\/form>/s', $withLogo, $logoForm);
    preg_match('/<form\b.*?<\/form>/s', $withoutLogo, $fallbackForm);
    check_auth_ui($logoForm[0] === $fallbackForm[0], "{$page} logo presence does not alter the form");
    unlink($tmpDir . '/logo.png');
    clearstatcache();
}
$css = (string) file_get_contents($root . '/assets/css/components.css');
check_auth_ui((bool) preg_match('/\.ap-auth-brand-image\s*\{([^}]+)\}/', $css, $backgroundRule), 'shared authentication background rule exists');
check_auth_ui(str_contains($backgroundRule[1], 'linear-gradient(') && str_contains($backgroundRule[1], 'rgba(230,107,18,.4)'), 'brand orange overlay stays translucent');
check_auth_ui(str_contains($backgroundRule[1], 'url("../../sfondologin.png")'), 'background resolves to the same public directory as the logo');
check_auth_ui(str_contains($backgroundRule[1], 'background-size: cover;') && str_contains($backgroundRule[1], 'background-position: center;'), 'background covers and centers the image');
check_auth_ui(str_contains($backgroundRule[1], 'background-color: var(--ap-orange);'), 'missing background retains the brand color');
check_auth_ui((bool) preg_match('/\.ap-auth-logo\s*\{([^}]+)\}/', $css, $logoRule), 'shared authentication logo rule exists');
check_auth_ui(str_contains($logoRule[1], 'height: 56px;') && str_contains($logoRule[1], 'max-width: 100%;') && str_contains($logoRule[1], 'object-fit: contain;'), 'logo remains proportional and fits narrow panels');
check_auth_ui(str_contains($logoRule[1], 'margin: 0 auto 1.5rem;'), 'logo is centered with space before the heading');
$registerPage = (string) file_get_contents($root . '/register.php');
check_auth_ui(!str_contains($registerPage, 'ap-auth-brand-image'), 'registration appearance remains unchanged');

echo "PASS: authentication UI regression ({$checks} checks)\n";
