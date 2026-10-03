import type { Metadata } from 'next';
import { AuthFrame } from '@/components/auth/AuthFrame';
import { ConfirmIdentityPage } from '@/components/auth/ConfirmIdentityPage';
import { safeRedirect } from '@/lib/auth';
import { t } from '@/lib/i18n';
import { translator } from '@/lib/messages';
import { api } from '@/lib/server';

export const metadata: Metadata = { title: 'Confirm it’s you' };

/** Confirm it's you before a sensitive change, then go back to it. */
export default async function ConfirmPasswordPage({ searchParams }: { searchParams: Promise<Record<string, string | string[] | undefined>> }) {
    const [query, me] = await Promise.all([searchParams, api<{ locale: string }>('/auth/me')]);
    const i18n = await translator(me.locale);

    return (
        <AuthFrame i18n={i18n} eyebrow={t(i18n, 'Account security')} heading={t(i18n, 'Confirm it’s you')} description={t(i18n, 'This is a sensitive action. Confirm your identity to continue; you won’t be asked again for a while.')}>
            <ConfirmIdentityPage redirect={safeRedirect(query.redirect)} />
        </AuthFrame>
    );
}
