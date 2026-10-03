'use client';

import Link from 'next/link';
import Script from 'next/script';
import { Form } from '@/components/form/Form';
import { InputField, PasswordField, Submit } from '@/components/form/fields';
import { useT } from '@/lib/i18n-client';

/** Create an account: the person's details, an access invitation or referral if there is one, and the bot checks. */
export function SignUpForm({ invite, invitedEmail, referral, turnstileSiteKey }: { invite?: string; invitedEmail: string | null; referral?: string; turnstileSiteKey: string | null }) {
    const { t, rich } = useT();

    return (
        <Form action="/api/app/auth/register" after={() => '/email/verify'}>
            {invite && <input type="hidden" name="invite" value={invite} />}
            {referral && <input type="hidden" name="referral" value={referral} />}
            <InputField name="name" label={t('Your name')} autoComplete="name" required autoFocus />
            <InputField name="email" label={t('Work email')} type="email" autoComplete="email" defaultValue={invitedEmail ?? undefined} required />
            <PasswordField name="password" label={t('Password')} description={t('At least 8 characters. A short sentence is easy to remember and hard to guess.')} autoComplete="new-password" required />
            <PasswordField name="password_confirmation" label={t('Confirm password')} autoComplete="new-password" required />
            {/* People never see this field; bots that fill every field give themselves away. */}
            <div className="absolute -left-[9999px]" aria-hidden="true">
                <label htmlFor="website">Website</label>
                <input id="website" type="text" name="website" tabIndex={-1} autoComplete="off" />
            </div>
            {turnstileSiteKey && (
                <>
                    <div className="cf-turnstile" data-sitekey={turnstileSiteKey} data-theme="auto" />
                    <Script src="https://challenges.cloudflare.com/turnstile/v0/api.js" strategy="afterInteractive" />
                </>
            )}
            <Submit className="w-full justify-center">{t('Create account')}</Submit>
            <p className="text-center text-xs leading-5 text-muted">
                {rich('By creating an account you agree to the :terms and :privacy.', {
                    terms: <Link href="/terms" className="font-semibold text-primary hover:underline">{t('terms of service')}</Link>,
                    privacy: <Link href="/privacy" className="font-semibold text-primary hover:underline">{t('privacy policy')}</Link>,
                })}
            </p>
        </Form>
    );
}
