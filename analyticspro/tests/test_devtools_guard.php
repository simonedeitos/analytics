<?php

declare(strict_types=1);

define('ANALYTICSPRO_ROOT', dirname(__DIR__));
require_once ANALYTICSPRO_ROOT . '/includes/functions.php';
require_once ANALYTICSPRO_ROOT . '/config/database.php';
require_once ANALYTICSPRO_ROOT . '/includes/layout.php';
function analyticspro_current_user(): ?array { return $GLOBALS['user']; }
$_SERVER['SCRIPT_FILENAME'] = ANALYTICSPRO_ROOT . '/dashboard.php';
foreach ([null, ['id' => 1, 'role' => 'user'], ['id' => 2, 'role' => 'subuser'], ['id' => 3, 'role' => 'admin']] as $user) {
    foreach (['true', 'false'] as $enabled) {
        $_ENV['ANALYTICSPRO_DEVTOOLS_GUARD_ENABLED'] = $enabled;
        ob_start();
        analyticspro_render_footer();
        $html = (string) ob_get_clean();
        if ($user) {
            if (!str_contains($html, 'assets/js/devtools-guard.js?v=')
                || !str_contains($html, 'window.ANALYTICSPRO_DEVTOOLS_GUARD_ENABLED = ' . $enabled . ';')) {
                throw new RuntimeException('Authenticated footer must load the versioned guard and server toggle.');
            }
        } elseif (str_contains($html, 'devtools-guard.js')) {
            throw new RuntimeException('Unauthenticated pages must not load the guard.');
        }
    }
}

$script = file_get_contents(dirname(__DIR__) . '/assets/js/devtools-guard.js');
$test = <<<'JS'
const assert = require('node:assert/strict');
const vm = require('node:vm');
function setup(enabled = true, config = {}) {
    let now = 10000, coarse = false;
    const events = {}, windowEvents = {};
    const content = {inert: false};
    const alreadyInert = {inert: true};
    const previousFocus = {isConnected: true, focus() { document.activeElement = this; }};
    const document = {
        activeElement: previousFocus, hidden: false, fullscreenElement: null,
        body: {
            children: [content, alreadyInert], style: {overflow: 'auto'},
            appendChild(element) { this.children.push(element); }
        },
        createElement() {
            return {
                style: {}, attributes: {}, setAttribute(key, value) { this.attributes[key] = value; },
                focus() { document.activeElement = this; },
                remove() { document.body.children = document.body.children.filter(child => child !== this); }
            };
        },
        addEventListener(type, handler) { events[type] = handler; }
    };
    let tick;
    const window = {
        ANALYTICSPRO_DEVTOOLS_GUARD_ENABLED: enabled,
        ANALYTICSPRO_DEVTOOLS_GUARD_CONFIG: config,
        innerWidth: 1200, outerWidth: 1200, innerHeight: 800, outerHeight: 900,
        matchMedia() { return {matches: coarse}; },
        visualViewport: {scale: 1, addEventListener() {}},
        setInterval(handler, ms) { assert.equal(ms, 1000); tick = handler; },
        addEventListener(type, handler) { windowEvents[type] = handler; }
    };
    vm.runInNewContext(SCRIPT, {window, document, Date: {now: () => now}});
    return {
        window, document, content, alreadyInert, events,
        tick() { now += 1000; if (tick) tick(); },
        resize() { windowEvents.resize(); },
        coarse(value) { coarse = value; },
        overlay() { return document.body.children.find(child => child.id === 'analyticspro-devtools-overlay'); }
    };
}
const s = setup();
s.tick(); s.tick(); s.tick();
assert.equal(s.overlay(), undefined, 'Normal browser chrome does not block');
s.window.outerWidth = 1500;
s.tick(); s.tick();
assert.equal(s.overlay(), undefined, 'Requires consecutive observations');
s.tick();
assert.ok(s.overlay());
assert.equal(s.overlay().attributes.role, 'alertdialog');
assert.ok(s.overlay().textContent.includes('strumenti di sviluppo aperti'));
assert.equal(s.content.inert, true);
assert.equal(s.document.activeElement, s.overlay());
assert.equal(s.document.body.style.overflow, 'hidden');
let prevented = false;
s.events.keydown({preventDefault() { prevented = true; }, stopImmediatePropagation() {}});
assert.ok(prevented, 'Keyboard interaction blocked');
s.window.outerWidth = 1200;
s.tick();
assert.equal(s.overlay(), undefined);
assert.equal(s.content.inert, false);
assert.equal(s.alreadyInert.inert, true);
assert.equal(s.document.body.style.overflow, 'auto');
s.window.outerHeight = 1150;
s.resize();
s.tick(); s.tick(); s.tick();
assert.equal(s.overlay(), undefined, 'Resize grace plus confirmation period');
s.tick();
assert.ok(s.overlay(), 'Bottom dock detected');
s.window.ANALYTICSPRO_DEVTOOLS_GUARD_ENABLED = false;
s.tick();
assert.equal(s.overlay(), undefined, 'Live disable removes overlay');
assert.equal(setup(false).overlay(), undefined);
for (const exclusion of ['mobile', 'coarse', 'zoom', 'fullscreen', 'bothAxes']) {
    const s = setup();
    s.window.outerWidth = 1600;
    if (exclusion === 'mobile') s.window.innerWidth = 500;
    if (exclusion === 'coarse') s.coarse(true);
    if (exclusion === 'zoom') s.window.visualViewport.scale = 1.5;
    if (exclusion === 'fullscreen') s.document.fullscreenElement = {};
    if (exclusion === 'bothAxes') s.window.outerHeight = 1200;
    s.tick(); s.tick(); s.tick(); s.tick();
    assert.equal(s.overlay(), undefined, exclusion);
}
const custom = setup(true, {threshold: 400, confirmations: 1});
custom.window.outerWidth = 1500;
custom.tick();
assert.equal(custom.overlay(), undefined);
custom.window.outerWidth = 1700;
custom.tick();
assert.ok(custom.overlay(), 'Configurable thresholds');
console.log('PASS: DevTools guard detection, interaction blocking, exclusions and live disable');
JS;
$test = 'const SCRIPT = ' . json_encode($script, JSON_THROW_ON_ERROR) . ";\n" . $test;
$process = proc_open(['node'], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('Unable to start Node test runner.');
}
fwrite($pipes[0], $test);
fclose($pipes[0]);
exit(proc_close($process));
