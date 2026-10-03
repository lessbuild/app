import type { Metadata, Viewport } from 'next';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import type { ReactNode } from 'react';
import './globals.css';

// Applies the saved theme before the first paint (lib/theme-boot.js).
const themeBoot = readFileSync(join(process.cwd(), 'lib', 'theme-boot.js'), 'utf8');

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
