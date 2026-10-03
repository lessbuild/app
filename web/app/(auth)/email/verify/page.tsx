import type { Metadata } from 'next';
import { redirect } from 'next/navigation';
import { AuthFrame } from '@/components/auth/AuthFrame';
import { VerifyEmailActions } from '@/components/auth/VerifyEmail';
import { t } from '@/lib/i18n';
import { translator } from '@/lib/messages';
import { api } from '@/lib/server';

export const metadata: Metadata = { title: 'Verify your email' };

/** Ask a new person to open the link in their verification email. */
export default async function VerifyEmailPage() {
    const me = await api<{ email: string; emailVerified: boolean; locale: string }>('/auth/me');
    if (me.emailVerified) {
        redirect('/dashboard');
    }
    const i18n = await translator(me.locale);

    return (
        <AuthFrame i18n={i18n} heading={t(i18n, 'Check your inbox')} description={t(i18n, 'We sent a verification link to :email. Open it to finish setting up your account.', { email: me.email })}>
            <VerifyEmailActions />
        </AuthFrame>
    );
}
