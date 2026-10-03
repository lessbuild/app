import type { Metadata } from 'next';
import Link from 'next/link';
import { redirect } from 'next/navigation';
import { AccessRequestForm } from '@/components/auth/AccessRequestForm';
import { AuthFrame } from '@/components/auth/AuthFrame';
import { t } from '@/lib/i18n';
import { guestTranslator } from '@/lib/locale';
import { api } from '@/lib/server';

export const metadata: Metadata = { title: 'Request access' };

/** Ask for an invitation while sign-up is closed; with sign-up open, go straight to it. */
export default async function RequestAccessPage() {
    const [i18n, form] = await Promise.all([guestTranslator(), api<{ registrationOpen: boolean; teamSizes: string[] }>('/access-requests')]);
    if (form.registrationOpen) {
        redirect('/register');
    }

    return (
        <AuthFrame
            i18n={i18n}
            heading={t(i18n, 'Request access')}
            description={t(i18n, 'We’re letting people in a few at a time. Tell us a little about what you’d build and we’ll email you an invitation.')}
            footer={<>{t(i18n, 'Already have an account?')} <Link href="/login" className="font-bold text-primary underline">{t(i18n, 'Sign in')}</Link></>}
        >
            <AccessRequestForm teamSizes={form.teamSizes} />
        </AuthFrame>
    );
}
