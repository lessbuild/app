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

    // Visiting any page with ?bp_ignore=1 stops this browser being counted (for a site's own team); ?bp_ignore=0 undoes it.
    try {
        var ignore = new URL(window.location.href).searchParams.get('bp_ignore');
        if (ignore === '1') window.localStorage.setItem('buildpusher_ignore', '1');
        if (ignore === '0') window.localStorage.removeItem('buildpusher_ignore');
    } catch (_) {}

    function collectionAllowed() {
        if (window.buildpusherAnalyticsOptOut === true) return false;
        try {
            if (window.localStorage.getItem('buildpusher_ignore') === '1') return false;
        } catch (_) {}
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
        if (/edg(e|a|ios)?\//i.test(ua)) return 'Edge';
        if (/opr\/|opera/i.test(ua)) return 'Opera';
        if (/samsungbrowser/i.test(ua)) return 'Samsung Internet';
        if (/yabrowser/i.test(ua)) return 'Yandex';
        if (/vivaldi/i.test(ua)) return 'Vivaldi';
        if (/duckduckgo/i.test(ua)) return 'DuckDuckGo';
        if (/firefox|fxios/i.test(ua)) return 'Firefox';
        if (/chrome|crios|chromium/i.test(ua)) return 'Chrome';
        if (/safari/i.test(ua)) return 'Safari';
        return 'Other';
    }

    function browserVersion() {
        var ua = navigator.userAgent;
        var match = ua.match(/(?:edg(?:e|a|ios)?|opr|samsungbrowser|yabrowser|vivaldi|firefox|fxios|crios|chrome)\/(\d+)/i) || ua.match(/version\/(\d+(?:\.\d+)?).*safari/i);
        return match ? match[1] : null;
    }

    function osVersion() {
        var ua = navigator.userAgent;
        var match = ua.match(/(?:iphone|cpu) os (\d+)[_.](\d+)/i) || ua.match(/android (\d+(?:\.\d+)?)/i) || ua.match(/windows nt (\d+\.\d+)/i) || ua.match(/mac os x (\d+)[_.](\d+)/i) || ua.match(/cros \S+ (\d+)/i);
        if (!match) return null;
        return match[2] !== undefined && /[_.]/.test(match[0]) && !/android|windows/i.test(match[0]) ? match[1] + '.' + match[2] : match[1];
    }

    function device() {
        return /tablet|ipad/i.test(navigator.userAgent) ? 'Tablet' : /mobile|iphone|android/i.test(navigator.userAgent) ? 'Mobile' : 'Desktop';
    }

    function operatingSystem() {
        var ua = navigator.userAgent;
        if (/windows/i.test(ua)) return 'Windows';
        if (/iphone|ipad|ipod/i.test(ua)) return 'iOS';
        if (/mac os|macintosh/i.test(ua)) return 'macOS';
        if (/android/i.test(ua)) return 'Android';
        if (/cros/i.test(ua)) return 'ChromeOS';
        if (/linux/i.test(ua)) return 'Linux';
        return 'Other';
    }

    function push(type, properties, path) {
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
            path: path || url.pathname,
            referrer_host: referrerHost,
            utm_source: url.searchParams.get('utm_source'),
            utm_medium: url.searchParams.get('utm_medium'),
            utm_campaign: url.searchParams.get('utm_campaign'),
            utm_term: url.searchParams.get('utm_term'),
            utm_content: url.searchParams.get('utm_content'),
            device: device(),
            screen: window.screen ? Math.round(window.screen.width) : null,
            browser: browser(),
            browser_version: browserVersion(),
            os: operatingSystem(),
            os_version: osVersion(),
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

    // Engagement: how long each page is visible and how far down it's scrolled, sent when the page is hidden or left
    // (the time since the last report, and the furthest scroll so far).
    var pagePath = window.location.pathname;
    var visibleSince = document.visibilityState === 'visible' ? Date.now() : null;
    var engagedMs = 0;
    var furthest = 0;
    function scrollDepth() {
        var root = document.documentElement;
        var height = Math.max(root.scrollHeight, document.body ? document.body.scrollHeight : 0);
        if (height <= 0) return 100;
        return Math.min(100, Math.round(((window.scrollY || root.scrollTop) + window.innerHeight) / height * 100));
    }
    function reportEngagement() {
        if (visibleSince !== null) {
            engagedMs += Date.now() - visibleSince;
            visibleSince = document.visibilityState === 'visible' ? Date.now() : null;
        }
        furthest = Math.max(furthest, scrollDepth());
        if (engagedMs >= 1000) {
            push('engagement', { scroll: furthest, engaged_ms: engagedMs }, pagePath);
            flush();
        }
        engagedMs = 0;
    }
    window.addEventListener('scroll', function () { furthest = Math.max(furthest, scrollDepth()); }, { passive: true });
    document.addEventListener('visibilitychange', function () {
        if (document.visibilityState === 'hidden') {
            reportEngagement();
        } else {
            visibleSince = Date.now();
        }
    });

    function navigationPageview() {
        if (window.location.href === lastPage) return;
        reportEngagement();
        lastPage = window.location.href;
        pagePath = window.location.pathname;
        furthest = 0;
        visibleSince = document.visibilityState === 'visible' ? Date.now() : null;
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

    // Opt-in page speed (data-vitals): real visitors' Largest Contentful Paint, Interaction to Next Paint, Cumulative
    // Layout Shift and Time to First Byte for the page they landed on, sent once when the page is hidden.
    if (script.dataset.vitals !== undefined && typeof PerformanceObserver === 'function') {
        var vitals = {};
        var vitalsSent = false;
        var clsWindow = 0;
        var clsWindowStart = 0;
        var clsLast = 0;
        var observe = function (type, callback, options) {
            try {
                new PerformanceObserver(function (list) { list.getEntries().forEach(callback); }).observe(Object.assign({ type: type, buffered: true }, options || {}));
            } catch (_) {}
        };
        observe('largest-contentful-paint', function (entry) { vitals.lcp = Math.round(entry.startTime); });
        observe('layout-shift', function (entry) {
            if (entry.hadRecentInput) return;
            // Shifts less than a second apart, within five seconds, form one window; CLS is the largest window.
            if (clsWindow && entry.startTime - clsLast < 1000 && entry.startTime - clsWindowStart < 5000) {
                clsWindow += entry.value;
            } else {
                clsWindow = entry.value;
                clsWindowStart = entry.startTime;
            }
            clsLast = entry.startTime;
            vitals.cls = Math.max(vitals.cls || 0, Math.round(clsWindow * 10000) / 10000);
        });
        observe('event', function (entry) {
            if (entry.interactionId) vitals.inp = Math.max(vitals.inp || 0, Math.round(entry.duration));
        }, { durationThreshold: 40 });
        try {
            var navigation = performance.getEntriesByType('navigation')[0];
            if (navigation && navigation.responseStart > 0) vitals.ttfb = Math.round(navigation.responseStart);
        } catch (_) {}
        var sendVitals = function (leaving) {
            if (vitalsSent || (leaving !== true && document.visibilityState !== 'hidden') || (vitals.lcp === undefined && vitals.ttfb === undefined)) return;
            vitalsSent = true;
            if (vitals.cls === undefined) vitals.cls = 0;
            push('vitals', vitals);
        };
        document.addEventListener('visibilitychange', sendVitals);
        window.addEventListener('pagehide', function () { sendVitals(true); });
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
    window.addEventListener('pagehide', function () { reportEngagement(); flush(); });
}());
