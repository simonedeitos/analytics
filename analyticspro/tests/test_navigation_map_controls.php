<?php

declare(strict_types=1);

define('ANALYTICSPRO_ROOT', dirname(__DIR__));

$testUser = ['id' => 1, 'role' => 'admin'];
function analyticspro_current_user(): array { return $GLOBALS['testUser']; }
function analyticspro_is_subuser(): bool { return false; }
function analyticspro_is_admin(): bool { return analyticspro_current_user()['role'] === 'admin'; }
function analyticspro_is_main_user(): bool { return analyticspro_current_user()['role'] === 'user'; }
function analyticspro_count_pending_registrations(): int { return 0; }
function analyticspro_base_url(string $path): string { return '/analyticspro/' . ltrim($path, '/'); }
function analyticspro_take_flash(): array { return []; }
function analyticspro_csrf_token(): string { return 'ui-test-token'; }
function analyticspro_h(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function analyticspro_full_name(array $user): string { return 'UI Test'; }

require_once ANALYTICSPRO_ROOT . '/includes/layout.php';
require_once ANALYTICSPRO_ROOT . '/includes/ui/helpers.php';

function check_controls(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$sections = analyticspro_nav_items($testUser, []);
foreach ($sections as $section) {
    foreach ($section['items'] as $item) {
        foreach ($item['page'] as $page) {
            $_SERVER['SCRIPT_FILENAME'] = ANALYTICSPRO_ROOT . '/' . $page;
            $_SERVER['REQUEST_URI'] = '/analyticspro/' . $page . '?tenant_id=all';
            check_controls(analyticspro_current_page() === $page, 'Current page: ' . $page);
            check_controls(analyticspro_page_matches($page . '?tenant_id=all#filters', $item['page']), 'Query/fragment matching: ' . $page);
            check_controls(analyticspro_page_matches('/' . $page, $item['page']), 'Leading slash matching: ' . $page);

            $testUser['role'] = $page === 'subutenti.php' ? 'user' : 'admin';
            ob_start();
            analyticspro_render_header('Test');
            analyticspro_render_footer();
            $html = (string) ob_get_clean();
            $dom = new DOMDocument();
            @$dom->loadHTML($html);
            $active = (new DOMXPath($dom))->query('//a[contains(@class, "ap-nav-link") and @aria-current="page"]');
            check_controls($active->length === 1, 'Exactly one active sidebar entry: ' . $page);
            check_controls($active->item(0)->getAttribute('href') === $item['url'], 'Correct active sidebar entry: ' . $page);
            check_controls(str_contains($active->item(0)->getAttribute('class'), ' active'), 'Active CSS class: ' . $page);
        }
    }
}
foreach (glob(ANALYTICSPRO_ROOT . '/admin/*.php') as $path) {
    if (str_starts_with(basename($path), '_')) {
        continue;
    }
    check_controls(analyticspro_page_matches('admin/' . basename($path), $sections[2]['items'][0]['page']), 'Admin page coverage: ' . $path);
}
unset($_SERVER['SCRIPT_FILENAME'], $_SERVER['PHP_SELF']);
foreach (['mappa.php', 'admin/import_ade.php'] as $page) {
    $_SERVER['SCRIPT_NAME'] = '/analyticspro/' . $page . '?tenant_id=all';
    check_controls(analyticspro_current_page() === $page, 'SCRIPT_NAME fallback: ' . $page);
    unset($_SERVER['SCRIPT_NAME']);
    $_SERVER['REQUEST_URI'] = '/analyticspro/' . $page . '?tenant_id=all';
    check_controls(analyticspro_current_page() === $page, 'REQUEST_URI fallback: ' . $page);
}
check_controls(!analyticspro_page_matches('admin/index.php', ['dashboard.php', 'index.php']), 'Do not confuse admin and root pages');
$_SERVER['SCRIPT_FILENAME'] = ANALYTICSPRO_ROOT . '/admin/tools/example.php';
check_controls(analyticspro_current_page() === 'admin/tools/example.php', 'Preserve deeper subdirectories');

foreach (['report.php', 'assegnati.php'] as $page) {
    $source = (string) file_get_contents(ANALYTICSPRO_ROOT . '/' . $page);
    check_controls(str_contains($source, 'data-can-export="0"'), 'Export disabled regardless of permissions: ' . $page);
}

$map = (string) file_get_contents(ANALYTICSPRO_ROOT . '/mappa.php');
$source = (string) file_get_contents(ANALYTICSPRO_ROOT . '/assets/js/analyticspro.js');
foreach (['id="toggle-map-filters"', 'aria-expanded="true"', 'aria-controls="map-search-filters"', 'data-bs-target="#map-search-filters"', 'Ricerca e Filtri', 'id="refresh-map"', 'aria-label="Aggiorna dati"', "'topbar_after_id' => 'map-search-filters'", "'topbar_after_class' => 'collapse show'"] as $contract) {
    check_controls(str_contains($map, $contract), 'Map controls contract: ' . $contract);
}
check_controls(str_contains($source, "if (t.closest('#refresh-map')) loadProperties()"), 'Refresh icon clicks reload data');
check_controls(str_contains($source, '    initMapToolbarToggle();'), 'Initialize filter preference on page load');

$functions = '';
foreach (['localStorageKey', 'readLocalFlag', 'writeLocalFlag', 'initMapToolbarToggle'] as $name) {
    check_controls((bool) preg_match('/    function ' . $name . '\([\s\S]*?(?=\n    (?:async )?function )/', $source, $match), 'Missing function: ' . $name);
    $functions .= $match[0] . "\n";
}
$test = <<<'JS'
const assert = require('node:assert/strict');
JS;
$test .= "\n" . $functions;
$test .= <<<'JS'

let missing = false;
let deniedStorage = false;
const stored = new Map();
const classes = new Set(['show']);
const listeners = {};
const attributes = {};
let resized = 0;
const toolbar = {
    classList: { toggle(name, enabled) { enabled ? classes.add(name) : classes.delete(name); } },
    addEventListener(name, handler) { listeners[name] = handler; }
};
const button = {
    classList: { toggle(name, enabled) { attributes[name] = enabled; } },
    setAttribute(name, value) { attributes[name] = value; }
};
global.document = { getElementById(id) { return missing ? null : (id === 'toggle-map-filters' ? button : toolbar); } };
global.window = {
    localStorage: {
        getItem(key) { if (deniedStorage) throw Error('denied'); return stored.get(key) ?? null; },
        setItem(key, value) { if (deniedStorage) throw Error('denied'); stored.set(key, value); }
    },
    dispatchEvent(event) { assert.equal(event.type, 'resize'); resized++; }
};
global.bootstrap = { Collapse: { getOrCreateInstance(element, options) {
    assert.equal(element, toolbar); assert.equal(options.toggle, false);
} } };
function expectOpen(open) {
    assert.equal(attributes['aria-expanded'], String(open));
    assert.equal(attributes.active, open);
    assert.equal(toolbar.inert, !open);
}
initMapToolbarToggle();
expectOpen(true);
assert.ok(classes.has('show'), 'Filters start open for new users');
listeners['hide.bs.collapse']({ target: toolbar });
expectOpen(false);
assert.equal(stored.get('analyticspro:map-filters-collapsed'), '1');
listeners['hidden.bs.collapse']({ target: toolbar });
assert.equal(resized, 1, 'Resize Leaflet after closing');
initMapToolbarToggle();
expectOpen(false);
assert.ok(!classes.has('show'), 'Restore collapsed preference on next visit');
listeners['show.bs.collapse']({ target: toolbar });
expectOpen(true);
assert.equal(stored.get('analyticspro:map-filters-collapsed'), '0');
listeners['shown.bs.collapse']({ target: toolbar });
assert.equal(resized, 2, 'Resize Leaflet after opening');
listeners['hide.bs.collapse']({ target: {} });
expectOpen(true);
deniedStorage = true;
initMapToolbarToggle();
expectOpen(true);
listeners['hide.bs.collapse']({ target: toolbar });
expectOpen(false);
missing = true;
initMapToolbarToggle();
console.log('PASS: navigation, disabled exports and persistent accessible map controls');
JS;

$process = proc_open(['node'], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
check_controls(is_resource($process), 'Unable to start Node test runner');
fwrite($pipes[0], $test);
fclose($pipes[0]);
exit(proc_close($process));
