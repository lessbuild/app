import type { RouteLocationNormalizedLoaded } from 'vue-router';
import type { NavLink } from '~/types/shell';

/**
 * Find the link for the page: the link of the service the page belongs to, else the link with the longest path the
 * page's path starts with.
 *
 * @param items The links to choose from.
 * @param route The page.
 */
export function currentNavUrl(items: NavLink[], route: Pick<RouteLocationNormalizedLoaded, 'path' | 'meta'>): string | null {
    // A page that isn't a tab of its own names the tab it belongs under, such as importing a server under Servers.
    const tab = route.meta.tab;
    if (typeof tab === 'string') {
        const parent = items.find((item) => (item.url.split('?')[0] ?? item.url).endsWith(`/${tab}`));
        if (parent) {
            return parent.url;
        }
    }
    const service = route.meta.service;
    if (typeof service === 'string') {
        const match = items.find((item) => item.service === service);
        if (match) {
            return match.url;
        }
    }
    let best: { url: string; path: string } | null = null;
    for (const item of items) {
        const path = item.url.split('?')[0] ?? item.url;
        if ((route.path === path || route.path.startsWith(path.endsWith('/') ? path : `${path}/`)) && (best === null || path.length > best.path.length)) {
            best = { url: item.url, path };
        }
    }
    return best?.url ?? null;
}
