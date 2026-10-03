import type { Shell } from '~/types/shell';

/**
 * Signed-in pages (those with the `app` layout): load the shell for the page's project and service (which also checks the session, the verified
 * email and the account's security rules) and switch to the person's language. Changing only the query (a filter, a
 * dialog) keeps the shell already loaded.
 */
export default defineNuxtRouteMiddleware(async (to, from) => {
    if (to.meta.layout !== 'app') {
        return;
    }
    const shell = useShell();
    if (shell.value !== null && import.meta.client && to.path === from.path) {
        return;
    }
    try {
        shell.value = await useApiReader()<Shell>('/shell', shellQuery(to));
        await setLocale(shell.value.locale);
    } catch (error) {
        return apiErrorNavigation(error, to);
    }
});
