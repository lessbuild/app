import type { NextConfig } from 'next';

// Every page is this app. In production Caddy sends the API and Laravel's own endpoints straight to Laravel; in
// development Next.js proxies the same paths to it.
const laravel = process.env.LARAVEL_URL ?? 'http://127.0.0.1:8000';

/** The paths Laravel answers: the JSON API, the admin panel, sign-in round trips, and machine endpoints. */
export const laravelPaths = [
    '/api/:path*',
    '/admin/:path*',
    '/livewire/:path*',
    '/build/:path*',
    '/css/filament/:path*',
    '/js/filament/:path*',
    '/fonts/filament/:path*',
    '/auth/:provider/:path*',
    '/sso/:path*',
    '/user/confirm-password/:provider',
    '/r/:code',
    '/analytics/google-analytics/callback',
    '/analytics/search-console/callback',
    '/analytics/ads/callback/:platform',
    '/cli/:path*',
    '/tracker/:path*',
    '/status/badge.svg',
    '/status/report.json',
    '/status/:slug/report.json',
    '/status/:slug/badge.svg',
    '/internal/:path*',
    '/servers/:id/provisioning/:path*',
    '/websites/:id/provisioning/:path*',
    '/builds/:id/deployment/:path*',
    '/environments/:id/wake',
    '/webhooks/:path*',
];

const config: NextConfig = {
    output: 'standalone',
    poweredByHeader: false,
    reactStrictMode: true,
    async rewrites() {
        return { beforeFiles: laravelPaths.map((source) => ({ source, destination: `${laravel}${source}` })), afterFiles: [], fallback: [] };
    },
};

export default config;
