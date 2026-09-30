// BuildPusher Analytics extras: outbound links, file downloads (data-outbound, data-downloads), page speed
// (data-vitals), click maps (data-clicks) and form analytics (data-forms). Loaded by v1.js only when its snippet has
// one of those attributes.
(function () {
    'use strict';

    var api = window.buildpusher;
    if (!api || !api._script || !api._push) return;
    var script = api._script;
    var push = api._push;

    // data-outbound counts clicks on links to other sites; data-downloads counts file downloads (common file types, or
    // the extensions listed, e.g. "pdf,zip").
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


    // Click maps (data-clicks): where on the page people click, as a position and a short description of the element
    // (its tag, id and first classes, or a few words of its text). No text people type is ever sent.
    function describe(element) {
        var tag = element.tagName.toLowerCase();
        if (element.id) return tag + '#' + element.id;
        var label = (element.getAttribute('aria-label') || element.innerText || element.value || '').trim().replace(/\s+/g, ' ');
        if (label && tag !== 'input' && tag !== 'textarea') return tag + ' "' + label.slice(0, 40) + '"';
        var classes = String(element.className || '').split(/\s+/).filter(Boolean).slice(0, 2);
        return tag + (classes.length ? '.' + classes.join('.') : '');
    }
    if (script.dataset.clicks !== undefined) {
        document.addEventListener('click', function (event) {
            var target = event.target && event.target.closest ? event.target.closest('a, button, input, select, textarea, label, summary, [role="button"], [onclick]') || event.target : null;
            if (!target || !target.tagName) return;
            var root = document.documentElement;
            var width = Math.max(root.scrollWidth, 1);
            var height = Math.max(root.scrollHeight, 1);
            push('click', {
                target: describe(target).slice(0, 80),
                x: Math.round(event.pageX / width * 1000) / 10,
                y: Math.round(event.pageY / height * 1000) / 10
            });
        }, true);
    }

    // Form analytics (data-forms): which fields people reach and where they give up. Only the form's and fields'
    // names are sent, never what's typed.
    if (script.dataset.forms !== undefined) {
        var formName = function (form) { return (form.getAttribute('name') || form.id || form.getAttribute('action') || 'form').slice(0, 80); };
        var seen = {};
        document.addEventListener('focusin', function (event) {
            var field = event.target;
            if (!field || !field.form || !/^(INPUT|SELECT|TEXTAREA)$/.test(field.tagName) || /^(hidden|submit|button)$/i.test(field.type || '')) return;
            var form = formName(field.form);
            var name = (field.getAttribute('name') || field.id || field.type || 'field').slice(0, 80);
            if (seen[form + '|' + name]) return;
            seen[form + '|' + name] = true;
            push('form', { form: form, field: name, action: 'focus' });
        }, true);
        document.addEventListener('submit', function (event) {
            if (event.target && event.target.tagName === 'FORM') push('form', { form: formName(event.target), action: 'submit' });
        }, true);
    }
}());
