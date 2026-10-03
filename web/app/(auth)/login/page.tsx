import type { Metadata } from 'next';
import Link from 'next/link';
import { AuthFrame } from '@/components/auth/AuthFrame';
import { SignInForm } from '@/components/auth/SignInForm';
import { SocialProviders } from '@/components/auth/SocialProviders';
import { safeRedirect, signInOptions } from '@/lib/auth';
import { t } from '@/lib/i18n';
import { guestTranslator } from '@/lib/locale';

export const metadata: Metadata = { title: 'Sign in' };

/** Sign in with a password, a passkey, single sign-on or a social provider. */
export default async function SignInPage({ searchParams }: { searchParams: Promise<Record<string, string | string[] | undefined>> }) {
    const query = await searchParams;
    const [i18n, options] = await Promise.all([guestTranslator(), signInOptions()]);

    return (
        <AuthFrame
            i18n={i18n}
            heading={t(i18n, 'Sign in')}
            description={t(i18n, 'One account for Deploy, Monitoring, Analytics and everything else on :app.', { app: 'BuildPusher' })}
            status={options.status}
            footer={<>{t(i18n, 'New here?')} <Link href="/register" className="font-bold text-primary underline">{t(i18n, 'Create an account')}</Link></>}
        >
            {options.error && <div className="ui-alert ui-alert--danger ui-alert-danger mb-5" role="alert">{options.error}</div>}
            <div className="grid gap-5">
                <SignInForm redirect={safeRedirect(query.redirect)} />
                <SocialProviders providers={options.socialProviders} />
            </div>
        </AuthFrame>
    );
}
