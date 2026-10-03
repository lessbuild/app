import type { Metadata } from 'next';
import Link from 'next/link';
import { AuthFrame } from '@/components/auth/AuthFrame';
import { ForgotPasswordForm } from '@/components/auth/PasswordForms';
import { t } from '@/lib/i18n';
import { guestTranslator } from '@/lib/locale';

export const metadata: Metadata = { title: 'Reset your password' };

/** Ask for a link to choose a new password. */
export default async function ForgotPasswordPage() {
    const i18n = await guestTranslator();

    return (
        <AuthFrame
            i18n={i18n}
            heading={t(i18n, 'Reset your password')}
            description={t(i18n, 'Enter your email and we’ll send a link to choose a new password.')}
            footer={<Link href="/login" className="font-bold text-primary underline">{t(i18n, 'Back to sign in')}</Link>}
        >
            <ForgotPasswordForm />
        </AuthFrame>
    );
}
