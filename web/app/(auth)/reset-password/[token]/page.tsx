import type { Metadata } from 'next';
import { AuthFrame } from '@/components/auth/AuthFrame';
import { ResetPasswordForm } from '@/components/auth/PasswordForms';
import { t } from '@/lib/i18n';
import { guestTranslator } from '@/lib/locale';

export const metadata: Metadata = { title: 'Choose a new password' };

/** Choose a new password, from the link in the reset email. */
export default async function ResetPasswordPage({ params, searchParams }: { params: Promise<{ token: string }>; searchParams: Promise<Record<string, string | string[] | undefined>> }) {
    const [{ token }, query, i18n] = await Promise.all([params, searchParams, guestTranslator()]);

    return (
        <AuthFrame i18n={i18n} heading={t(i18n, 'Choose a new password')}>
            <ResetPasswordForm token={token} email={typeof query.email === 'string' ? query.email : ''} />
        </AuthFrame>
    );
}
