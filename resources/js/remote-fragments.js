// Modal contents loaded on demand: <div data-fragment-src="url"> inside a <dialog> fetches its HTML (with the
// X-Fragment header, so the server answers with just the fragment) the first time the dialog opens. Inside it,
// <form data-fragment-form> (GET) reloads the fragment instead of leaving the page, and a control with
// data-fragment-autosubmit reloads it as soon as it changes. With data-fragment-refresh it reloads every time the dialog
// opens (for things that change, like notifications). If loading fails, the fragment links to the full page.
const load = async (container, url) => {
    container.setAttribute('aria-busy', 'true');
    try {
        // X-Requested-With keeps the fetch out of the session's "previous page", so redirects back still go to the
        // page the modal was opened on.
        const response = await fetch(url, { headers: { Accept: 'text/html', 'X-Fragment': '1', 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin' });
        if (!response.ok || response.redirected) throw new Error(`HTTP ${response.status}`);
        const html = await response.text();
        // A whole page answers with its data-modal-content part; a fragment answers with just itself.
        const page = new DOMParser().parseFromString(html, 'text/html');
        const part = page.querySelector('[data-modal-content]');
        container.innerHTML = part ? part.innerHTML : html;
        container.dataset.fragmentLoaded = 'true';
        container.dataset.fragmentUrl = url;
        // Forms remember which modal they came from, so a failed submit reopens it.
        const dialog = container.closest('dialog');
        container.querySelectorAll('form[method="POST" i], form[method="post"]').forEach((form) => {
            if (dialog?.id && !form.querySelector('input[name="_modal"]')) {
                const marker = document.createElement('input');
                marker.type = 'hidden';
                marker.name = '_modal';
                marker.value = dialog.id;
                form.append(marker);
            }
        });
    } catch {
        const fallback = document.createElement('a');
        fallback.href = url;
        fallback.className = 'ui-link';
        fallback.textContent = container.dataset.fragmentFallback || 'Open the form on its own page';
        container.replaceChildren(fallback);
    } finally {
        container.removeAttribute('aria-busy');
    }
};

document.querySelectorAll('[data-fragment-src]').forEach((container) => {
    const dialog = container.closest('dialog');

    container.addEventListener('submit', (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.hasAttribute('data-fragment-form')) return;
        event.preventDefault();
        const url = new URL(form.action, window.location.href);
        new FormData(form).forEach((value, name) => url.searchParams.set(name, String(value)));
        load(container, url.toString());
    });
    // Links to other versions of the loaded page (such as another monitor type) reload it here; links back to the page
    // the modal is on (such as Cancel) just close the modal.
    container.addEventListener('click', (event) => {
        const link = event.target instanceof Element ? event.target.closest('a[href]') : null;
        if (!link || event.defaultPrevented || event.metaKey || event.ctrlKey || event.shiftKey || link.target) return;
        const url = new URL(link.href, window.location.href);
        const loaded = new URL(container.dataset.fragmentUrl || container.dataset.fragmentSrc, window.location.href);
        if (url.origin !== window.location.origin) return;
        if (url.pathname === loaded.pathname) {
            event.preventDefault();
            load(container, url.toString());
        } else if (url.pathname === window.location.pathname && dialog) {
            event.preventDefault();
            dialog.close();
        }
    });
    container.addEventListener('change', (event) => {
        if (event.target instanceof Element && event.target.hasAttribute('data-fragment-autosubmit')) {
            event.target.closest('form')?.requestSubmit();
        }
    });

    const loadOnce = () => {
        const stale = container.dataset.fragmentLoaded !== 'true' || container.hasAttribute('data-fragment-refresh');
        if (stale && container.getAttribute('aria-busy') !== 'true') {
            load(container, container.dataset.fragmentSrc);
        }
    };
    if (!dialog || dialog.open) {
        loadOnce();
        if (!dialog) return;
    }
    new MutationObserver(() => {
        if (dialog.open) loadOnce();
    }).observe(dialog, { attributes: true, attributeFilter: ['open'] });
});
