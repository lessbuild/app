import type { Shell } from '~/types/shell';

// The signed-in frame's data (the person, their accounts and projects, the navigation), loaded by the signed-in route
// middleware for each page and read by layouts/app.vue.

/** The current shell, or null before the signed-in middleware has loaded it. */
export function useShell() {
    return useState<Shell | null>('shell', () => null);
}

/** The project and service a route is in, as the shell needs them. */
export function shellQuery(route: { params: Record<string, unknown>; meta: Record<string, unknown> }) {
    return {
        project: typeof route.params.project === 'string' ? route.params.project : undefined,
        // A service's page says which it is; the page for a service that's still off has it in the address.
        service: typeof route.meta.service === 'string' ? route.meta.service : typeof route.params.service === 'string' ? route.params.service : undefined,
        area: typeof route.meta.area === 'string' ? route.meta.area : undefined,
    };
}

/** Load the shell again (after a change to projects, services or counts). */
export async function refreshShell(): Promise<void> {
    const shell = useShell();
    if (shell.value === null) {
        return;
    }
    const read = useApiReader();
    shell.value = await read<Shell>('/shell', shellQuery(useRoute())).catch(() => shell.value);
    if (shell.value) {
        await setLocale(shell.value.locale);
    }
}
