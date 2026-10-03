import type { Metadata, Viewport } from 'next';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import type { ReactNode } from 'react';
import './globals.css';

// The Blade layout's theme boot script, taken from its component and inlined, so the saved theme (light or dark,
// palette, density…) is applied before the first paint, exactly as on the Blade pages.
const themeBoot = (readFileSync(join(process.cwd(), '..', 'api', 'resources', 'views', 'components', 'signal', 'theme-boot.blade.php'), 'utf8')
    .match(/<script[^>]*>([\s\S]*?)<\/script>/)?.[1] ?? '');

export const metadata: Metadata = {
    title: { default: 'BuildPusher', template: '%s · BuildPusher' },
    robots: { index: false, follow: false },
    manifest: '/manifest.webmanifest',
};

export const viewport: Viewport = {
    width: 'device-width',
    initialScale: 1,
    themeColor: '#f4f7fb',
};

/** The document around every Next.js page, with the same theme settings as the Blade layout. */
export default function RootLayout({ children }: { children: ReactNode }) {
    return (
        <html
            lang="en"
            className="min-h-full"
            data-storage-namespace="buildpusher-signal"
            data-default-preset="modern"
            data-default-appearance="system"
            data-default-palette="graphite"
            data-default-density="comfortable"
            data-default-corners="subtle"
            data-default-font="system"
            data-default-motion="system"
            data-default-contrast="default"
            suppressHydrationWarning
        >
            <head>
                <script dangerouslySetInnerHTML={{ __html: themeBoot }} />
            </head>
            <body className="antialiased transition-colors">{children}</body>
        </html>
    );
}
