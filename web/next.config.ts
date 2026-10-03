import type { NextConfig } from 'next';

// Where Laravel answers. In production Caddy sends /api and the public endpoints straight to Laravel; in development
// Next.js proxies them. Pages that haven't moved to Next.js yet fall back to Laravel too, so the app stays whole while
// it moves over (docs/nextjs-and-audit-plan.md).
const laravel = process.env.LARAVEL_URL ?? 'http://127.0.0.1:8000';

const config: NextConfig = {
    output: 'standalone',
    poweredByHeader: false,
    reactStrictMode: true,
    // The monorepo root, so the Signal styles and translations in ../api are part of the build.
    outputFileTracingRoot: new URL('..', import.meta.url).pathname,
    async rewrites() {
        return {
            beforeFiles: [
                { source: '/api/:path*', destination: `${laravel}/api/:path*` },
                { source: '/build/:path*', destination: `${laravel}/build/:path*` },
            ],
            afterFiles: [],
            fallback: [{ source: '/:path*', destination: `${laravel}/:path*` }],
        };
    },
};

export default config;
