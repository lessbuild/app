import tailwindcss from '@tailwindcss/vite';

// The app's pages, rendered on the server and then in the browser. Laravel (api/) answers /api/app with JSON; in
// production Caddy sends the API and Laravel's own endpoints straight to it, and server/middleware/laravel.ts proxies
// the same paths in development.
export default defineNuxtConfig({
    compatibilityDate: '2026-09-01',
    devtools: { enabled: false },
    modules: ['@nuxt/eslint'],
    css: ['~/assets/css/main.css'],
    // Components are named by file (UiButton, ApiForm), wherever they sit under components/.
    components: [{ path: '~/components', pathPrefix: false }],
    vite: { plugins: [tailwindcss()] },
    typescript: { strict: true },
    runtimeConfig: {
        // Where the server reaches Laravel (NUXT_LARAVEL_URL); browsers use the same origin.
        laravelUrl: process.env.LARAVEL_URL ?? 'http://127.0.0.1:8000',
        public: {
            // The app's own host (NUXT_PUBLIC_APP_HOST), so requests to it skip the status page domain lookup.
            appHost: '',
        },
    },
    app: {
        head: {
            meta: [
                { name: 'viewport', content: 'width=device-width, initial-scale=1' },
                { name: 'theme-color', content: '#f4f7fb' },
                { name: 'robots', content: 'noindex, nofollow' },
            ],
            link: [{ rel: 'manifest', href: '/manifest.webmanifest' }],
        },
    },
    routeRules: {
        // Signed-in pages are personal: never cached by a proxy.
        '/**': { headers: { 'Cache-Control': 'private, no-store' } },
        // A shared Analytics report's embed is meant to be framed by other sites (the only page that may be); shared and
        // view-only reports stay out of search engines.
        '/share/analytics/**': { headers: { 'Cache-Control': 'private, no-store', 'X-Robots-Tag': 'noindex' } },
        '/share/analytics/*/embed': { headers: { 'Cache-Control': 'private, no-store', 'X-Robots-Tag': 'noindex', 'Content-Security-Policy': 'frame-ancestors *' } },
        '/analytics/view/**': { headers: { 'Cache-Control': 'private, no-store', 'X-Robots-Tag': 'noindex' } },
        // Public status pages may be framed by other sites, as before.
        '/status/**': { headers: { 'Cache-Control': 'private, no-store', 'Content-Security-Policy': 'frame-ancestors *' } },
    },
    eslint: { config: { stylistic: false } },
});
