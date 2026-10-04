// Sends the paths Laravel answers to it: the JSON API, the admin panel, sign-in round trips and machine endpoints.
// In production Caddy routes these before they reach this server; this keeps development (and a misrouted request)
// working the same way.

/** The paths Laravel answers; `*` is one segment, `**` the rest of the path. */
export const laravelPaths = [
    '/api/**',
    '/sanctum/**',
    '/admin/**',
    '/admin',
    '/livewire/**',
    '/build/**',
    '/css/filament/**',
    '/js/filament/**',
    '/fonts/filament/**',
    '/auth/*/**',
    '/sso/**',
    '/github-app/connect',
    '/github-app/callback',
    '/r/*',
    '/analytics/google-analytics/callback',
    '/analytics/search-console/callback',
    '/analytics/ads/callback/*',
    '/cli/**',
    '/tracker/**',
    '/status/badge.svg',
    '/status/report.json',
    '/status/*/report.json',
    '/status/*/badge.svg',
    '/status/*/embed',
    '/status/deployer/*',
    '/status/monitor/*',
    // A status page's custom domain: its badge, JSON report and embed at the root (ServeStatusPageDomains maps them).
    '/badge.svg',
    '/report.json',
    '/embed',
    '/internal/**',
    '/servers/*/provisioning/**',
    '/websites/*/provisioning/**',
    '/builds/*/deployment/**',
    '/environments/*/wake',
    '/webhooks/**',
];

/** Turn a path pattern into a regular expression: `**` matches the rest of the path, `*` one segment. */
function pattern(path: string): RegExp {
    const source = path.split('**').map((part) => part.replace(/[.]/g, '\\.').replace(/\*/g, '[^/]+')).join('.*');

    return new RegExp(`^${source}$`);
}

const patterns = laravelPaths.map(pattern);

/**
 * Pages whose POST is Laravel's: signed links in emails that mail clients unsubscribe with in one click (RFC 8058),
 * while a GET of the same address shows the page here.
 */
export const laravelPosts = ['/email/getting-started/*/stop', '/status/subscriptions/*/unsubscribe/*', '/status/webhooks/*/unsubscribe/*'];

const postPatterns = laravelPosts.map(pattern);

export default defineEventHandler((event) => {
    const path = event.path.split('?')[0] ?? '/';
    if (patterns.some((pattern) => pattern.test(path)) || (event.method === 'POST' && postPatterns.some((pattern) => pattern.test(path)))) {
        const host = getRequestHeader(event, 'x-forwarded-host') ?? getRequestHeader(event, 'host') ?? '';
        const proto = getRequestHeader(event, 'x-forwarded-proto') ?? getRequestProtocol(event);

        return proxyRequest(event, `${useRuntimeConfig(event).laravelUrl}${event.path}`, {
            fetchOptions: { redirect: 'manual' },
            headers: {
                // h3 leaves Accept out of proxied requests; Laravel needs it to answer with JSON.
                accept: getRequestHeader(event, 'accept') ?? '*/*',
                // So Laravel builds addresses for the public host, not its own port.
                'x-forwarded-host': host,
                'x-forwarded-proto': proto,
                'x-forwarded-port': host.includes(':') ? (host.split(':').pop() ?? '') : (proto === 'http' ? '80' : '443'),
                'x-forwarded-for': getRequestHeader(event, 'x-forwarded-for') ?? getRequestIP(event) ?? '',
            },
        });
    }
});
