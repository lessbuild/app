import type { RouterConfig } from '@nuxt/schema';
import type { RouteLocationNormalized } from 'vue-router';

/**
 * Find the element an address's #fragment points at, if there is one.
 *
 * @param hash The fragment, with its #.
 */
function anchor(hash: string): HTMLElement | null {
    try {
        return hash ? document.querySelector<HTMLElement>(hash) : null;
    } catch {
        return null;
    }
}

/**
 * Put the new page where it belongs: in the signed-in app the page scrolls inside its frame (`[data-scroll-frame]`),
 * not the window, so scroll that to the top or to the #fragment; elsewhere the window does as usual.
 *
 * @param to The page arrived at.
 * @param from The page left.
 * @param saved Where the window was on this page before, going back or forward.
 */
function place(to: RouteLocationNormalized, from: RouteLocationNormalized, saved: { left: number; top: number } | null) {
    const frame = to.meta.layout === 'app' ? document.querySelector<HTMLElement>('[data-scroll-frame]') : null;
    if (frame) {
        const target = anchor(to.hash);
        if (target) {
            target.scrollIntoView({ block: 'start' });
        } else if (to.path !== from.path) {
            frame.scrollTo(0, 0);
        }
        return false;
    }
    if (saved) {
        return saved;
    }
    return to.hash && anchor(to.hash) ? { el: to.hash, top: 80 } : { left: 0, top: 0 };
}

export default <RouterConfig>{
    scrollBehavior(to, from, saved) {
        // Only the query changed (a filter, a dialog): stay where you are.
        if (to.path === from.path && to.hash === from.hash) {
            return false;
        }
        if (to.path === from.path) {
            return place(to, from, saved);
        }
        const nuxtApp = useNuxtApp();
        return new Promise((resolve) => nuxtApp.hooks.hookOnce('page:finish', () => {
            requestAnimationFrame(() => resolve(place(to, from, saved)));
        }));
    },
};
