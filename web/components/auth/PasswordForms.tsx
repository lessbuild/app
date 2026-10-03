'use client';

import { useState } from 'react';
import { Form } from '@/components/form/Form';
import { InputField, PasswordField, Submit } from '@/components/form/fields';
import { useT } from '@/lib/i18n-client';

/** Ask for a reset link; afterwards say it's on its way (whether or not the address has an account). */
export function ForgotPasswordForm() {
    const { t } = useT();
    const [sent, setSent] = useState(false);

    if (sent) {
        return <div className="ui-alert ui-alert--success ui-alert-success" role="status">{t('A link to choose a new password is on its way. It works for an hour.')}</div>;
    }

    return (
        <Form action="/api/app/auth/forgot-password" after={() => { setSent(true); return null; }}>
            <InputField name="email" label={t('Email address')} type="email" autoComplete="email" required autoFocus />
            <Submit className="w-full justify-center">{t('Email reset link')}</Submit>
        </Form>
    );
}

/** Choose a new password from the emailed link, then sign in with it. */
export function ResetPasswordForm({ token, email }: { token: string; email: string }) {
    const { t } = useT();

    return (
        <Form action="/api/app/auth/reset-password" after={() => '/login'}>
            <input type="hidden" name="token" value={token} />
            <InputField name="email" label={t('Email address')} type="email" autoComplete="username" defaultValue={email} required />
            <PasswordField name="password" label={t('New password')} autoComplete="new-password" required autoFocus />
            <PasswordField name="password_confirmation" label={t('Confirm new password')} autoComplete="new-password" required />
            <Submit className="w-full justify-center">{t('Save password')}</Submit>
        </Form>
    );
}
