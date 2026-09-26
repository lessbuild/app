import { initDrawer } from './signal/navigation.js';
import { initGlobalCommandPalette } from './signal/library.js';

export function initWorkspaceNavigation() {
    const drawer = document.querySelector('[data-mobile-menu]');
    const dialog = document.querySelector('[data-global-command-dialog]');
    const input = dialog?.querySelector('[data-global-command-search]');

    initDrawer('[data-mobile-menu]', '[data-mobile-menu-toggle]', 1024);

    if (!dialog || !input) return;

    dialog.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            dialog.close();
        }
    }, true);

    // Keep Signal's input key events from also moving focus a second time at the dialog.
    input.addEventListener('keydown', (event) => {
        if (['Home', 'End'].includes(event.key)) {
            event.stopPropagation();
            return;
        }

        if (!['ArrowDown', 'ArrowUp', 'Enter'].includes(event.key)) return;

        event.preventDefault();
        event.stopImmediatePropagation();

        const items = [...dialog.querySelectorAll('[data-global-command-item]')]
            .filter((item) => !item.classList.contains('hidden'));
        const item = event.key === 'ArrowUp' ? items.at(-1) : items[0];

        if (event.key === 'Enter') item?.click();
        else item?.focus();
    }, true);

    document.addEventListener('keydown', (event) => {
        if (!drawer || drawer.classList.contains('hidden')) return;

        const target = event.target;
        const editing = target instanceof HTMLElement && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));
        const openingCommand = ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k')
            || (event.key === '/' && !editing && !event.metaKey && !event.ctrlKey && !event.altKey);

        if (openingCommand) {
            document.querySelector('[data-mobile-menu-toggle][aria-controls]')?.click();
            return;
        }

        if (event.key !== 'Tab') return;

        // Native details hide their links, but those links still match Signal's focus selector.
        const items = [...drawer.querySelectorAll('a[href], button:not([disabled]), summary, input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')]
            .filter((item) => item.getClientRects().length > 0 && getComputedStyle(item).visibility !== 'hidden');
        const first = items[0];
        const last = items.at(-1);
        event.stopImmediatePropagation();

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last?.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first?.focus();
        }
    }, true);

    initGlobalCommandPalette();
}
