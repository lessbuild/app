const focusableSelector = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

const focusableIn = element => [...element.querySelectorAll(focusableSelector)];

/**
 * Initialize Signal's public mobile drawer behavior without coupling it to
 * Livewire. Public pages do not load Livewire's Alpine focus plugin.
 */
export function initSignalPublicDrawers() {
    document.querySelectorAll('[data-mobile-drawer]').forEach(drawer => {
        const toggles = [...document.querySelectorAll('[data-mobile-toggle]')]
            .filter(toggle => toggle.getAttribute('aria-controls') === drawer.id || drawer.contains(toggle));
        const desktopNavigation = drawer.parentElement?.querySelector('[data-desktop-navigation]');
        let lastTrigger = null;

        const isOpen = () => !drawer.classList.contains('hidden');
        const close = (restoreFocus = true) => {
            drawer.classList.add('hidden');
            drawer.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('overflow-hidden');
            document.documentElement.classList.remove('drawer-open');
            toggles.forEach(toggle => toggle.setAttribute('aria-expanded', 'false'));

            if (restoreFocus && lastTrigger?.isConnected) {
                lastTrigger.focus();
            }
        };
        const open = trigger => {
            lastTrigger = trigger;
            drawer.classList.remove('hidden');
            drawer.setAttribute('aria-hidden', 'false');
            drawer.setAttribute('aria-modal', 'true');
            document.body.classList.add('overflow-hidden');
            document.documentElement.classList.add('drawer-open');
            toggles.forEach(toggle => toggle.setAttribute('aria-expanded', 'true'));
            window.requestAnimationFrame(() => focusableIn(drawer)[0]?.focus());
        };

        toggles.forEach(toggle => toggle.addEventListener('click', event => {
            event.preventDefault();

            if (drawer.contains(toggle) || isOpen()) {
                close();
            } else {
                open(toggle);
            }
        }));

        drawer.querySelectorAll('a').forEach(link => link.addEventListener('click', () => close()));
        drawer.addEventListener('click', event => {
            if (event.target === drawer) {
                close();
            }
        });

        document.addEventListener('keydown', event => {
            if (event.key === 'Escape' && isOpen()) {
                close();

                return;
            }

            if (event.key !== 'Tab' || !isOpen()) {
                return;
            }

            const nodes = focusableIn(drawer);

            if (nodes.length === 0) {
                event.preventDefault();

                return;
            }

            const first = nodes[0];
            const last = nodes[nodes.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            } else if (!drawer.contains(document.activeElement)) {
                event.preventDefault();
                first.focus();
            }
        });

        window.addEventListener('resize', () => {
            if (window.matchMedia('(min-width: 768px)').matches && isOpen()) {
                close(false);
                desktopNavigation?.querySelector('a[href]')?.focus();
            }
        });
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSignalPublicDrawers, { once: true });
} else {
    initSignalPublicDrawers();
}
