'use client';

import { useState } from 'react';
import { Form } from '@/components/form/Form';
import { InputField, Submit } from '@/components/form/fields';
import { useT } from '@/lib/i18n-client';

/** The authenticator code, or (behind a link, since it's rarely needed) a recovery code. */
export function TwoFactorForm({ redirect }: { redirect: string }) {
    const { t } = useT();
    const [recovery, setRecovery] = useState(false);

    return (
        <Form action="/api/app/auth/two-factor-challenge" after={() => redirect}>
            {recovery ? (
                <InputField key="recovery" name="recovery_code" label={t('Recovery code')} autoComplete="off" required autoFocus />
            ) : (
                <InputField key="code" name="code" label={t('Authentication code')} autoComplete="one-time-code" inputMode="numeric" pattern="[0-9]*" maxLength={6} required autoFocus />
            )}
            <Submit className="w-full justify-center">{t('Verify and sign in')}</Submit>
            <button type="button" className="ui-link justify-self-center text-sm font-bold" onClick={() => setRecovery(!recovery)}>
                {recovery ? t('Use a code from your app instead') : t('Lost your device? Use a recovery code')}
            </button>
        </Form>
    );
}
