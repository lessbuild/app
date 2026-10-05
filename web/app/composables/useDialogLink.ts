import type { ComputedRef } from 'vue';
import type { RouteLocationRaw } from 'vue-router';

// The app-wide dialogs (connecting a provider, adding an alert destination) are mounted by the app layout and open
// over whatever page someone is on with `?dialog=<id>`. A page can mount its own copy instead by listing the id in its
// `definePageMeta({ dialogs: [...] })`.

/**
 * Links that open an app-wide dialog over the current page, keeping its address (in a drawer, the page underneath).
 *
 * @return A function that takes the dialog's id and gives the link.
 */
export function useDialogLink(): (id: string) => RouteLocationRaw {
    const router = useRouter();

    return (id) => ({ query: { ...router.currentRoute.value.query, dialog: id } });
}

/**
 * Whether the layout's copy of an app-wide dialog should be mounted: it's open, and the page doesn't have its own.
 *
 * @param id The dialog's id.
 * @return Whether to mount it.
 */
export function useSharedDialog(id: string): ComputedRef<boolean> {
    const router = useRouter();

    return computed(() => {
        const route = router.currentRoute.value;
        const own = Array.isArray(route.meta.dialogs) ? (route.meta.dialogs as string[]) : [];
        return route.query.dialog === id && !own.includes(id);
    });
}

/**
 * Run something when an app-wide dialog closes, such as loading choices again after a provider was connected.
 *
 * @param id The dialog's id.
 * @param callback What to run.
 */
export function onDialogClosed(id: string, callback: () => void): void {
    const router = useRouter();

    watch(() => router.currentRoute.value.query.dialog, (now, before) => {
        if (before === id && now !== id) {
            callback();
        }
    });
}
