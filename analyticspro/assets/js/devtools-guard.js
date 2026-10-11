(function () {
    'use strict';

    // Deterrente UX, NON una misura di sicurezza: proteggere sempre i dati lato server.
    if (window.ANALYTICSPRO_DEVTOOLS_GUARD_ENABLED === false) return;

    var config = window.ANALYTICSPRO_DEVTOOLS_GUARD_CONFIG || {};
    function positiveNumber(value, fallback) {
        return typeof value === 'number' && Number.isFinite(value) && value > 0 ? value : fallback;
    }
    var threshold = positiveNumber(config.threshold, 200);
    var minWidth = positiveNumber(config.minViewportWidth, 768);
    var confirmations = positiveNumber(config.confirmations, 3);
    var resizeGraceMs = positiveNumber(config.resizeGraceMs, 2000);
    var overlay = null;
    var blockedElements = [];
    var previousFocus = null;
    var previousOverflow = '';
    var hits = 0;
    var resizedAt = 0;

    function unblock() {
        if (!overlay) return;
        overlay.remove();
        overlay = null;
        blockedElements.forEach(function (entry) { entry.element.inert = entry.inert; });
        blockedElements = [];
        document.body.style.overflow = previousOverflow;
        if (previousFocus && previousFocus.isConnected) previousFocus.focus();
    }

    function block() {
        if (overlay) return;
        previousFocus = document.activeElement;
        previousOverflow = document.body.style.overflow;
        overlay = document.createElement('div');
        overlay.id = 'analyticspro-devtools-overlay';
        overlay.setAttribute('role', 'alertdialog');
        overlay.setAttribute('aria-modal', 'true');
        overlay.setAttribute('aria-label', 'Strumenti di sviluppo aperti');
        overlay.tabIndex = -1;
        overlay.textContent = "L'uso dell'applicazione non è consentito con gli strumenti di sviluppo aperti. Chiudili per continuare.";
        overlay.style.cssText = 'position:fixed;inset:0;z-index:2147483647;background:#fff;color:#182230;display:flex;align-items:center;justify-content:center;padding:2rem;text-align:center;font:600 1.25rem/1.5 sans-serif;pointer-events:auto;overflow:auto;';
        Array.from(document.body.children).forEach(function (element) {
            blockedElements.push({ element: element, inert: element.inert });
            element.inert = true;
        });
        document.body.style.overflow = 'hidden';
        document.body.appendChild(overlay);
        overlay.focus();
    }

    function check() {
        if (window.ANALYTICSPRO_DEVTOOLS_GUARD_ENABLED === false) {
            hits = 0;
            unblock();
            return;
        }
        // Touch/mobile, fullscreen e zoom non standard non offrono dimensioni affidabili.
        var scale = window.visualViewport ? window.visualViewport.scale : 1;
        if (window.innerWidth < minWidth || window.matchMedia('(pointer: coarse)').matches
            || document.fullscreenElement || Math.abs(scale - 1) > 0.05
            || !window.outerWidth || !window.outerHeight) {
            hits = 0;
            unblock();
            return;
        }
        var widthGap = window.outerWidth - window.innerWidth;
        var heightGap = window.outerHeight - window.innerHeight;
        // Il dock modifica soprattutto un asse; zoom/resize spesso modificano entrambi.
        var detected = (widthGap > threshold && heightGap < threshold)
            || (heightGap > threshold && widthGap < threshold);
        if (!detected) {
            hits = 0;
            unblock();
        } else if (!document.hidden && Date.now() - resizedAt >= resizeGraceMs) {
            hits++;
            if (hits >= confirmations) block();
        }
    }

    function suppressInteraction(event) {
        if (!overlay) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        if (document.activeElement !== overlay) overlay.focus();
    }
    ['keydown', 'keyup', 'click', 'pointerdown', 'wheel', 'touchstart'].forEach(function (type) {
        document.addEventListener(type, suppressInteraction, { capture: true, passive: false });
    });
    document.addEventListener('focusin', function (event) {
        if (overlay && event.target !== overlay) overlay.focus();
    });
    window.addEventListener('resize', function () {
        resizedAt = Date.now();
        hits = 0;
    });
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', function () {
            resizedAt = Date.now();
            hits = 0;
        });
    }
    window.setInterval(check, 1000);
    check();
}());
