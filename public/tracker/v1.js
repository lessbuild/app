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

    // Opt-in automatic events, switched on with attributes on the script tag:
    // data-not-found on a 404 page's snippet counts the missing page; data-outbound counts clicks on links to other
    // sites; data-downloads counts file downloads (common file types, or the extensions listed, e.g. "pdf,zip").
    if (script.dataset.notFound !== undefined) {
        window.buildpusher.track('not_found');
    }
    var outbound = script.dataset.outbound !== undefined;
    var downloads = script.dataset.downloads;
    var extensions = (downloads || 'pdf,zip,rar,7z,gz,tgz,tar,dmg,exe,msi,pkg,deb,rpm,apk,csv,xls,xlsx,doc,docx,ppt,pptx,txt,rtf,epub,mp3,mp4,mov,avi,wav,iso')
        .toLowerCase().split(',').map(function (extension) { return extension.trim().replace(/^\./, ''); });
    function linkClicked(event) {
        var link = event.target && event.target.closest ? event.target.closest('a[href]') : null;
        if (!link) return;
        var url;
        try {
            url = new URL(link.href, window.location.href);
        } catch (_) {
            return;
        }
        if (url.protocol !== 'http:' && url.protocol !== 'https:') return;
        var external = url.hostname !== window.location.hostname;
        var extension = (url.pathname.split('.').pop() || '').toLowerCase();
        if (downloads !== undefined && url.pathname.indexOf('.') !== -1 && extensions.indexOf(extension) !== -1) {
            window.buildpusher.track('file_download', { file: (external ? url.hostname : '') + url.pathname });
        } else if (outbound && external) {
            window.buildpusher.track('outbound_link', { url: url.hostname + url.pathname });
        }
    }
    if (outbound || downloads !== undefined) {
        document.addEventListener('click', linkClicked, true);
        document.addEventListener('auxclick', function (event) { if (event.button === 1) linkClicked(event); }, true);
    }

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
