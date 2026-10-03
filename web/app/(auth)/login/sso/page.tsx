import type { Metadata } from 'next';
import Link from 'next/link';
import { AuthFrame } from '@/components/auth/AuthFrame';
import { SsoForm } from '@/components/auth/SsoForm';
import { t } from '@/lib/i18n';
import { guestTranslator } from '@/lib/locale';

export const metadata: Metadata = { title: 'Single sign-on' };

/** Start single sign-on from a work email address. */
export default async function SsoPage() {
    const i18n = await guestTranslator();

    return (
        <AuthFrame
            i18n={i18n}
            heading={t(i18n, 'Sign in with single sign-on')}
            description={t(i18n, 'Enter your work email and we’ll send you to your company’s sign-in page.')}
            footer={<Link href="/login" className="font-bold text-primary underline">{t(i18n, 'Sign in with a password instead')}</Link>}
        >
            <SsoForm />
        </AuthFrame>
    );
}
