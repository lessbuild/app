import type { InjectionKey } from 'vue';

// A create or edit page opened in a drawer over the page someone was on (`?panel=<its address>`), instead of in place
// of it. The page's header becomes the drawer's title, saving closes the drawer and reloads the page underneath, and
// Cancel just closes it.

/** What a page shown in the drawer can ask of it. */
export type PagePanel = {
    /** Close the drawer and stay on the page underneath. */
    close: () => void;
    /** Show the page's title and description as the drawer's heading. */
    setHeading: (title: string, description?: string | null) => void;
    /** Whether saving should still go where the API says (the page's next step), closing the drawer on the way. */
    follows: boolean;
};

export const pagePanelKey: InjectionKey<PagePanel> = Symbol('page-panel');

/** The drawer this page is shown in, or null when it's the page itself. */
export function usePagePanel(): PagePanel | null {
    return inject(pagePanelKey, null);
}

/** Whether a path is a create or edit page, which opens in a drawer over the current page. */
export function isPanelPath(path: string): boolean {
    return /\/(create|edit|new)$/.test(path.split('?')[0] ?? path);
}
