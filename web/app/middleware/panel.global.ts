// Following a link to a create or edit page from within the app opens it in a drawer over the current page
// (`?panel=`), so the person stays where they were. Opening the address directly still shows it as a page.
export default defineNuxtRouteMiddleware((to, from) => {
    if (import.meta.server || from.matched.length === 0 || to.query.panel !== undefined || !isPanelPath(to.path)) {
        return;
    }
    // Only pages in the signed-in app; the public site and sign-in pages keep their own addresses.
    const layout = to.meta.layout ?? from.meta.layout;
    if (layout !== 'app' || from.meta.layout !== 'app') {
        return;
    }
    const { dialog: _dialog, ...rest } = from.query;
    const query = { ...rest, panel: to.fullPath };

    return navigateTo({ path: from.path, query, hash: from.hash });
});
