import type { Metadata } from 'next';
import { AuthFrame } from '@/components/auth/AuthFrame';
import { TwoFactorForm } from '@/components/auth/TwoFactorForm';
import { safeRedirect } from '@/lib/auth';
import { t } from '@/lib/i18n';
import { guestTranslator } from '@/lib/locale';

export const metadata: Metadata = { title: 'Two-factor verification' };

/** The second step of signing in: a code from the authenticator app, or a recovery code. */
export default async function TwoFactorPage({ searchParams }: { searchParams: Promise<Record<string, string | string[] | undefined>> }) {
    const [query, i18n] = await Promise.all([searchParams, guestTranslator()]);

    return (
        <AuthFrame i18n={i18n} eyebrow={t(i18n, 'Account security')} heading={t(i18n, 'Verify it’s you')} description={t(i18n, 'Enter the code from your authenticator app, or one of your recovery codes.')}>
            <TwoFactorForm redirect={safeRedirect(query.redirect)} />
        </AuthFrame>
    );
}
