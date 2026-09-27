(function () {
    'use strict';

    var script = document.currentScript;
    var site = script && script.dataset.site;
    if (!site) return;

    var endpoint = new URL('/api/v1/collect/' + encodeURIComponent(site), script.src).toString();
    var queue = [];
    var lastPage = window.location.href;

    function id() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }

        return 'bp-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2);
    }

    function collectionAllowed() {
        if (window.buildpusherAnalyticsOptOut === true) return false;
        var consentCallback = script.dataset.consent;
        if (!consentCallback) return true;
        try {
            return typeof window[consentCallback] === 'function' && window[consentCallback]() === true;
        } catch (_) {
            return false;
        }
    }

    function browser() {
        var ua = navigator.userAgent;
        if (/edg/i.test(ua)) return 'Edge';
        if (/chrome|crios/i.test(ua)) return 'Chrome';
        if (/safari/i.test(ua) && !/chrome/i.test(ua)) return 'Safari';
        if (/firefox|fxios/i.test(ua)) return 'Firefox';
        return 'Other';
    }

    function device() {
        return /tablet|ipad/i.test(navigator.userAgent) ? 'Tablet' : /mobile|iphone|android/i.test(navigator.userAgent) ? 'Mobile' : 'Desktop';
    }

    function operatingSystem() {
        var ua = navigator.userAgent;
        if (/windows/i.test(ua)) return 'Windows';
        if (/mac os|macintosh/i.test(ua)) return 'macOS';
        if (/android/i.test(ua)) return 'Android';
        if (/iphone|ipad|ios/i.test(ua)) return 'iOS';
        if (/linux/i.test(ua)) return 'Linux';
        return 'Other';
    }

    function push(type, properties) {
        if (!collectionAllowed()) return;
        var url = new URL(window.location.href);
        var referrerHost = null;
        try {
            referrerHost = document.referrer ? new URL(document.referrer).hostname : null;
        } catch (_) {}
        queue.push({
            id: id(),
            type: type,
            occurred_at: new Date().toISOString(),
            path: url.pathname,
            referrer_host: referrerHost,
            utm_source: url.searchParams.get('utm_source'),
            utm_medium: url.searchParams.get('utm_medium'),
            utm_campaign: url.searchParams.get('utm_campaign'),
            device: device(),
            browser: browser(),
            os: operatingSystem(),
            properties: properties || null
        });
        flush();
    }

    function flush() {
        if (!queue.length) return;
        var events = queue.splice(0, 20);
        send(events, 0);
    }

    function send(events, attempt) {
        fetch(endpoint, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ events: events }), keepalive: true, credentials: 'omit' }).then(function (response) {
            if (!response.ok && attempt < 2) {
                window.setTimeout(function () { send(events, attempt + 1); }, 250 * (attempt + 1));
            }
        }).catch(function () {
            if (attempt < 2) {
                window.setTimeout(function () { send(events, attempt + 1); }, 250 * (attempt + 1));
            }
        });
    }

    function navigationPageview() {
        if (window.location.href === lastPage) return;
        lastPage = window.location.href;
        push('pageview');
    }

    window.buildpusher = window.buildpusher || {};
    window.buildpusher.track = function (name, properties) {
        if (!name || typeof name !== 'string') return;
        push('event', Object.assign({ name: name.slice(0, 80) }, properties || {}));
    };
    window.buildpusher.pageview = function () {
        push('pageview');
        lastPage = window.location.href;
    };

    push('pageview');
    ['pushState', 'replaceState'].forEach(function (method) {
        var original = window.history[method];
        window.history[method] = function () {
            var result = original.apply(this, arguments);
            navigationPageview();
            return result;
        };
    });
    window.addEventListener('popstate', navigationPageview);
    window.addEventListener('pagehide', flush, { once: true });
}());
