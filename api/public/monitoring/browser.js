/* BuildPusher browser errors: <script src="https://buildpusher.com/monitoring/browser.js" data-key="bpb_…" data-release="v1.2.3" defer></script> */
(function () {
    'use strict';
    var script = document.currentScript;
    var key = script && script.dataset.key;
    if (!key || !window.fetch) return;
    var endpoint = new URL('/api/v1/browser/' + encodeURIComponent(key) + '/errors', script.src).toString();
    var release = script.dataset.release || undefined;
    var seen = {};
    var sent = 0;
    var queue = [];
    var timer = null;

    function flush() {
        timer = null;
        if (!queue.length) return;
        var errors = queue.splice(0, 10);
        fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ release: release, errors: errors }), keepalive: true, credentials: 'omit' }).catch(function () {});
    }

    function report(kind, message, source, line, column, stack) {
        message = String(message || 'Unknown error').slice(0, 1000);
        var signature = message + '|' + source + '|' + line + '|' + column;
        // At most 25 errors a page, and each distinct error once.
        if (seen[signature] || sent >= 25) return;
        seen[signature] = true;
        sent++;
        queue.push({ kind: kind, message: message, source: source ? String(source).slice(0, 2048) : undefined, line: line || undefined, column: column || undefined, stack: stack ? String(stack).slice(0, 8000) : undefined, page: window.location.href.slice(0, 2048) });
        if (!timer) timer = window.setTimeout(flush, 1000);
    }

    window.addEventListener('error', function (event) {
        // Resource load failures (a missing image) have no message; they aren't JavaScript errors.
        if (!event.message) return;
        report('error', event.message, event.filename, event.lineno, event.colno, event.error && event.error.stack);
    });
    window.addEventListener('unhandledrejection', function (event) {
        var reason = event.reason;
        report('unhandledrejection', reason && reason.message ? reason.message : String(reason), null, null, null, reason && reason.stack);
    });
    window.addEventListener('pagehide', flush);
    window.buildpusherError = function (error) {
        report('error', error && error.message ? error.message : String(error), null, null, null, error && error.stack);
    };
}());
