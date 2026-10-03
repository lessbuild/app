import Link from 'next/link';
import type { ReactNode } from 'react';
import { t, type Translator } from '@/lib/i18n';
import { I18nProvider } from '@/lib/i18n-client';

/** The frame for sign-in pages: the brand, one card with the heading, and an optional line underneath. */
export function AuthFrame({ i18n, heading, eyebrow, description, status, footer, children }: {
    i18n: Translator;
    heading: string;
    eyebrow?: string;
    description?: ReactNode;
    status?: string | null;
    footer?: ReactNode;
    children: ReactNode;
}) {
    return (
        <I18nProvider value={i18n}>
            <main id="main-content" className="mx-auto grid min-h-screen w-full max-w-md place-items-center px-4 py-10 sm:px-6">
                <div className="w-full">
                    <Link href="/" className="mb-6 inline-flex items-center gap-3 rounded-control text-lg font-extrabold tracking-tight text-ink focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-focus">
                        <span className="grid h-10 w-10 place-items-center rounded-card bg-ink text-surface" aria-hidden="true">↗</span>
                        <span>BuildPusher</span>
                    </Link>
                    <div className="ui-card p-6 sm:p-8">
                        {eyebrow && <p className="ui-eyebrow">{eyebrow}</p>}
                        <h1 className="mt-2 text-2xl font-extrabold tracking-tight text-ink">{heading}</h1>
                        {description && <p className="mt-2 text-sm leading-6 text-muted">{description}</p>}
                        {status && <div className="ui-alert ui-alert--success ui-alert-success mt-5" role="status">{status}</div>}
                        <div className="mt-6">{children}</div>
                    </div>
                    {footer && <div className="mt-5 text-center text-sm text-muted">{footer}</div>}
                    <p className="mt-8 text-center text-xs text-subtle">
                        <Link href="/privacy" className="hover:text-ink">{t(i18n, 'Privacy')}</Link>
                        <span aria-hidden="true"> · </span>
                        <Link href="/terms" className="hover:text-ink">{t(i18n, 'Terms')}</Link>
                        <span aria-hidden="true"> · </span>
                        <Link href="/help" className="hover:text-ink">{t(i18n, 'Help centre')}</Link>
                    </p>
                </div>
            </main>
        </I18nProvider>
    );
}
