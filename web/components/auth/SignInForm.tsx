'use client';

import Link from 'next/link';
import { useState } from 'react';
import { Form } from '@/components/form/Form';
import { Checkbox, InputField, PasswordField, Submit } from '@/components/form/fields';
import { useT } from '@/lib/i18n-client';
import { passkeysSupported, signInWithPasskey } from '@/lib/passkeys';

/** Sign in with a password (then the two-factor challenge if it's on) or a passkey, and go back where they were going. */
export function SignInForm({ redirect }: { redirect: string }) {
    const { t } = useT();
    const [passkeyStatus, setPasskeyStatus] = useState<string | null>(null);
    const [working, setWorking] = useState(false);

    async function passkey() {
        if (!passkeysSupported()) {
            setPasskeyStatus(t('This browser does not support passkeys.'));
            return;
        }
        setWorking(true);
        setPasskeyStatus(t('Waiting for your passkey…'));
        try {
            const remember = (document.getElementById('remember') as HTMLInputElement | null)?.checked ?? false;
            await signInWithPasskey(remember);
            window.location.assign(redirect);
        } catch {
            setPasskeyStatus(t('Passkey sign-in could not be completed. Please try again.'));
            setWorking(false);
        }
    }

    return (
        <Form action="/api/app/auth/login" after={(data) => (data.two_factor === true ? `/two-factor-challenge?redirect=${encodeURIComponent(redirect)}` : redirect)}>
            <InputField name="email" label={t('Email address')} type="email" autoComplete="username webauthn" required autoFocus />
            <PasswordField name="password" label={t('Password')} autoComplete="current-password" required />
            <div className="flex flex-wrap items-center justify-between gap-3">
                <Checkbox name="remember" id="remember" label={t('Remember me')} />
                <Link href="/forgot-password" className="rounded-sm text-sm font-bold text-primary hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
                    {t('Forgot password?')}
                </Link>
            </div>
            <Submit className="w-full justify-center">{t('Sign in')}</Submit>
            <div className="grid gap-2">
                <button type="button" className="ui-btn ui-btn-secondary w-full justify-center" onClick={passkey} disabled={working} aria-busy={working || undefined}>
                    {t('Sign in with a passkey')}
                </button>
                <p role="status" aria-live="polite" className={passkeyStatus ? "text-center text-sm text-muted" : "sr-only"}>{passkeyStatus}</p>
                <Link href="/login/sso" className="ui-btn ui-btn-quiet w-full justify-center">{t('Sign in with single sign-on')}</Link>
            </div>
        </Form>
    );
}
