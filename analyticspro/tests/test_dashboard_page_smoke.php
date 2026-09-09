<?php

declare(strict_types=1);

$root = dirname(__DIR__);
if (!defined('ANALYTICSPRO_ROOT')) {
    define('ANALYTICSPRO_ROOT', $root);
}

require_once $root . '/includes/functions.php';
require_once $root . '/includes/ui/helpers.php';
require_once $root . '/includes/dashboard_stats.php';

$dashboardPath = $root . '/dashboard.php';
$source = file_get_contents($dashboardPath);
if (!is_string($source) || $source === '') {
    fwrite(STDERR, "FAIL: impossibile leggere dashboard.php\n");
    exit(1);
}

$errors = [];

if (!str_contains($source, "require_once __DIR__ . '/includes/dashboard_stats.php';")) {
    $errors[] = 'dashboard.php deve includere includes/dashboard_stats.php per evitare fatal su analyticspro_dashboard_period_key().';
}
if (str_contains($source, 'analitiche.php')) {
    $errors[] = 'dashboard.php non deve mantenere riferimenti alla pagina rimossa analitiche.php.';
}
if (!str_contains($source, 'data-dashboard-filter-control="province"')) {
    $errors[] = 'dashboard.php deve esporre il filtro avanzato provincia.';
}
if (!str_contains($source, 'data-dashboard-filter-control="category"')) {
    $errors[] = 'dashboard.php deve esporre il filtro avanzato categoria.';
}
if (!str_contains($source, "'canvas_id' => 'chart-gender'")) {
    $errors[] = 'dashboard.php deve includere il grafico Distribuzione sesso migrato da analitiche.php.';
}
if (analyticspro_dashboard_period_key('30d') !== '30d') {
    $errors[] = 'analyticspro_dashboard_period_key(30d) deve restituire 30d.';
}

try {
    $html = analyticspro_ui_page_header('Dashboard', 'Smoke test dashboard', '<button type="button">Azione</button>', ['eyebrow' => 'Test']);
    $html .= analyticspro_ui_kpi_card([
        'icon' => 'bi-buildings',
        'label' => 'Immobili visibili',
        'data_key' => 'properties',
        'sparkline_id' => 'spark-properties',
        'icon_tone' => 'primary',
    ]);
    $html .= analyticspro_ui_chart_card([
        'title' => 'Distribuzione sesso',
        'icon' => 'bi-gender-ambiguous',
        'canvas_id' => 'chart-gender',
        'height' => '300',
    ]);
    $html .= analyticspro_ui_empty_state([
        'icon' => 'bi-shield-lock',
        'title' => 'Analitiche non disponibili',
        'message' => 'Smoke test fallback',
    ]);
} catch (Throwable $exception) {
    $errors[] = 'Le partial UI usate dalla dashboard non devono lanciare eccezioni: ' . $exception->getMessage();
    $html = '';
}

if ($html === '' || !str_contains($html, 'chart-gender') || !str_contains($html, 'Immobili visibili')) {
    $errors[] = 'Il rendering smoke dei componenti dashboard deve produrre l’HTML atteso senza fatal error.';
}

if ($errors !== []) {
    foreach ($errors as $error) {
        fwrite(STDERR, "FAIL: {$error}\n");
    }
    exit(1);
}

echo "PASS: dashboard page smoke OK\n";
