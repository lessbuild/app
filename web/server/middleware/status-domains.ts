// A status page's custom domain shows that page and nothing else: its root renders the page (pages/index.vue asks
// which one), the page's own requests and assets go through, and every other page of the app is a 404 there. Laravel's
// paths (the badge, report and embed) were already sent on by laravel.ts. The app's own host skips the lookup when
// NUXT_PUBLIC_APP_HOST names it.

/** How long to remember whether a host is a status page's domain, in milliseconds. */
const REMEMBER = 60_000;

/** Hosts looked up recently: whether each is a status page's domain, and until when that's remembered. */
const known = new Map<string, { status: boolean; until: number }>();

/** The paths a status page's domain serves besides its root: the page's API calls, assets and other status pages. */
const allowed = [/^\/$/, /^\/_nuxt\//, /^\/__nuxt/, /^\/api\//, /^\/sanctum\//, /^\/status\//, /^\/favicon\.ico$/, /^\/manifest\.webmanifest$/, /^\/robots\.txt$/];

/** Find out (or remember) whether a host is the verified domain of a published status page. */
async function isStatusDomain(host: string, laravelUrl: string): Promise<boolean> {
    const now = Date.now();
    const remembered = known.get(host);
    if (remembered && remembered.until > now) {
        return remembered.status;
    }
    const status = /^[a-z0-9.-]{1,253}$/.test(host)
        ? await $fetch(`${laravelUrl}/api/app/status-domains/${host}`, { headers: { accept: 'application/json' } }).then(() => true, () => false)
        : false;
    if (known.size > 1000) {
        known.clear();
    }
    known.set(host, { status, until: now + REMEMBER });

    return status;
}

export default defineEventHandler(async (event) => {
    const config = useRuntimeConfig(event);
    const host = (getRequestHeader(event, 'x-forwarded-host') ?? getRequestHeader(event, 'host') ?? '').toLowerCase().split(':')[0] ?? '';
    if (host === '' || host === config.public.appHost) {
        return;
    }
    const path = event.path.split('?')[0] ?? '/';
    if (allowed.some((pattern) => pattern.test(path))) {
        return;
    }
    if (await isStatusDomain(host, config.laravelUrl)) {
        throw createError({ statusCode: 404, statusMessage: 'Not Found' });
    }
});
