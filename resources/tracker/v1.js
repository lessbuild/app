(function () {
    'use strict';

    var script = document.currentScript;
    var site = script && script.dataset.site;
    if (!site) return;

    // data-api points collection at a first-party proxy on the site's own domain (e.g. data-api="/bp/event"), so
    // blockers that stop third-party analytics don't stop it; otherwise events go to wherever the script came from.
    var endpoint = script.dataset.api
        ? new URL(script.dataset.api.replace(/\/$/, '') + '/' + encodeURIComponent(site), window.location.href).toString()
        : new URL('/api/v1/collect/' + encodeURIComponent(site), script.src).toString();
    var queue = [];
    var lastPage = window.location.href;
    // data-hash counts #/pages as separate pages, for sites that route with the URL's hash.
    var hashMode = script.dataset.hash !== undefined;
    // Site search: the query parameters that hold what visitors searched for (data-search="q,term" to choose).
    var searchParams = (script.dataset.search || 'q,s,search,query,term,keyword').split(',').map(function (name) { return name.trim(); });

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

    // Opt-in retention (data-retention): a random ID kept in this browser, so returning visitors can be recognised
    // across days. It's stored on the visitor's device, so sites should ask for consent where the law requires it.
    function returningId() {
        if (script.dataset.retention === undefined) return null;
        try {
            var stored = window.localStorage.getItem('buildpusher_id');
            if (!stored) {
                stored = id();
                window.localStorage.setItem('buildpusher_id', stored);
            }
            return stored;
        } catch (_) {
            return null;
        }
    }

    function searchTerm(url) {
        for (var i = 0; i < searchParams.length; i++) {
            var value = url.searchParams.get(searchParams[i]);
            if (value) return value.slice(0, 200);
        }
        return undefined;
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
            path: path || (hashMode ? url.pathname + url.hash : url.pathname),
            hash: hashMode || undefined,
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
            returning: returningId(),
            search: type === 'pageview' ? searchTerm(url) : undefined,
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
    var pagePath = hashMode ? window.location.pathname + window.location.hash : window.location.pathname;
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
        pagePath = hashMode ? window.location.pathname + window.location.hash : window.location.pathname;
        furthest = 0;
        visibleSince = document.visibilityState === 'visible' ? Date.now() : null;
        push('pageview');
    }

    window.buildpusher = window.buildpusher || {};
    window.buildpusher.track = function (name, properties) {
        if (!name || typeof name !== 'string') return;
        push('event', Object.assign({ name: name.slice(0, 80) }, properties || {}));
    };
    // buildpusher.search('term', 0) records a search and how many results it found, to spot searches that find nothing.
    window.buildpusher.search = function (term, results) {
        if (!term || typeof term !== 'string') return;
        push('event', { name: 'search', term: term.slice(0, 200), results: typeof results === 'number' ? Math.round(results) : undefined });
    };
    window.buildpusher.pageview = function () {
        push('pageview');
        lastPage = window.location.href;
    };

    push('pageview');

    // data-not-found on a 404 page's snippet counts the missing page.
    if (script.dataset.notFound !== undefined) {
        window.buildpusher.track('not_found');
    }
    // Link, download and page speed tracking live in a second file, loaded only when the snippet asks for them.
    window.buildpusher._push = push;
    window.buildpusher._script = script;
    if (script.dataset.outbound !== undefined || script.dataset.downloads !== undefined || script.dataset.vitals !== undefined) {
        var extras = document.createElement('script');
        extras.async = true;
        extras.src = new URL('v1-extras.js', script.src).toString();
        document.head.appendChild(extras);
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
    if (hashMode) window.addEventListener('hashchange', navigationPageview);

    // Events without JavaScript: give any element a class like bp-event-name=Signup (and bp-event-plan=pro for a
    // property); clicking it sends the event.
    document.addEventListener('click', function (event) {
        var element = event.target && event.target.closest ? event.target.closest('[class*="bp-event-name="]') : null;
        if (!element) return;
        var name = null;
        var properties = {};
        String(element.className).split(/\s+/).forEach(function (token) {
            var match = token.match(/^bp-event-([a-z0-9_]+)=(.+)$/i);
            if (!match) return;
            var value = decodeURIComponent(match[2].replace(/\+/g, ' '));
            if (match[1] === 'name') name = value; else properties[match[1]] = value;
        });
        if (name) window.buildpusher.track(name, properties);
    }, true);
    window.addEventListener('pagehide', function () { reportEngagement(); flush(); });
}());
