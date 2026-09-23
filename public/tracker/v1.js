(function () {
    'use strict';

    var script = document.currentScript;
    var site = script && script.dataset.site;
    if (!site) return;

    var endpoint = new URL('/api/v1/collect/' + encodeURIComponent(site), script.src).toString();
    var queue = [];
    var lastPage = window.location.href;
    var identities = {};

    function randomId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }

        if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
            var bytes = new Uint8Array(16);
            window.crypto.getRandomValues(bytes);
            bytes[6] = (bytes[6] & 15) | 64;
            bytes[8] = (bytes[8] & 63) | 128;

            var hex = '';
            for (var index = 0; index < bytes.length; index++) {
                hex += ('0' + bytes[index].toString(16)).slice(-2);
            }

            return hex.slice(0, 8) + '-' + hex.slice(8, 12) + '-' + hex.slice(12, 16) + '-' + hex.slice(16, 20) + '-' + hex.slice(20);
        }

        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function (character) {
            var value = Math.random() * 16 | 0;
            return (character === 'x' ? value : (value & 3 | 8)).toString(16);
        });
    }

    function anonymousId(type, storageName) {
        if (identities[type]) return identities[type];

        var key = 'buildpusher:v1:' + type + ':' + site;
        try {
            var storage = window[storageName];
            var existing = storage.getItem(key);
            if (existing && /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(existing)) {
                identities[type] = existing;
                return existing;
            }

            identities[type] = randomId();
            storage.setItem(key, identities[type]);
            return identities[type];
        } catch (_) {
            identities[type] = randomId();
            return identities[type];
        }
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
            id: randomId(),
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
            visitor: anonymousId('visitor', 'localStorage'),
            session: anonymousId('session', 'sessionStorage'),
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
        push('event', Object.assign({}, properties || {}, { name: name.slice(0, 80) }));
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
