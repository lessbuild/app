// Modal contents loaded on demand: <div data-fragment-src="url"> inside a <dialog> fetches its HTML (with the
// X-Fragment header, so the server answers with just the fragment) the first time the dialog opens. Inside it,
// <form data-fragment-form> (GET) reloads the fragment instead of leaving the page, and a control with
// data-fragment-autosubmit reloads it as soon as it changes. If loading fails, the fragment links to the full page.
const load = async (container, url) => {
    container.setAttribute('aria-busy', 'true');
    try {
        const response = await fetch(url, { headers: { Accept: 'text/html', 'X-Fragment': '1' }, credentials: 'same-origin' });
        if (!response.ok || response.redirected) throw new Error(`HTTP ${response.status}`);
        container.innerHTML = await response.text();
        container.dataset.fragmentLoaded = 'true';
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
    container.addEventListener('change', (event) => {
        if (event.target instanceof Element && event.target.hasAttribute('data-fragment-autosubmit')) {
            event.target.closest('form')?.requestSubmit();
        }
    });

    const loadOnce = () => {
        if (container.dataset.fragmentLoaded !== 'true' && container.getAttribute('aria-busy') !== 'true') {
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
