import type { Metadata } from 'next';
import { cookies } from 'next/headers';
import Link from 'next/link';
import { AuthFrame } from '@/components/auth/AuthFrame';
import { SignUpForm } from '@/components/auth/SignUpForm';
import { SocialProviders } from '@/components/auth/SocialProviders';
import { signInOptions } from '@/lib/auth';
import { t } from '@/lib/i18n';
import { guestTranslator } from '@/lib/locale';

export const metadata: Metadata = { title: 'Create an account' };

/** Create an account: open to everyone, or by access invitation while sign-up is closed. */
export default async function SignUpPage({ searchParams }: { searchParams: Promise<Record<string, string | string[] | undefined>> }) {
    const query = await searchParams;
    const invite = typeof query.invite === 'string' && query.invite.length === 64 ? query.invite : undefined;
    const [i18n, options, jar] = await Promise.all([guestTranslator(), signInOptions(invite), cookies()]);
    const referral = jar.get('bp_referral')?.value;
    const invited = options.invitedEmail !== null;

    return (
        <AuthFrame
            i18n={i18n}
            heading={t(i18n, 'Create your account')}
            description={t(i18n, 'Start free. Turn on the services you need for each project and pay only for what you use.')}
            footer={<>{t(i18n, 'Already have an account?')} <Link href="/login" className="font-bold text-primary underline">{t(i18n, 'Sign in')}</Link></>}
        >
            {!options.registrationOpen && !invited ? (
                <div className="grid gap-4">
                    <div className="ui-alert ui-alert--info" role="status">
                        {t(i18n, 'Sign-up is by invitation for now. If a team invited you, use the email they invited.')}
                    </div>
                    <Link href="/request-access" className="ui-btn ui-btn-primary w-full justify-center">{t(i18n, 'Request access')}</Link>
                </div>
            ) : (
                <div className="grid gap-5">
                    <SignUpForm invite={invite} invitedEmail={options.invitedEmail} referral={referral} turnstileSiteKey={options.turnstileSiteKey} />
                    <SocialProviders providers={options.socialProviders} />
                </div>
            )}
        </AuthFrame>
    );
}
