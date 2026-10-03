'use client';

import { Form } from '@/components/form/Form';
import { InputField, Submit } from '@/components/form/fields';
import { useT } from '@/lib/i18n-client';

/** A work email, then off to the company's identity provider. */
export function SsoForm() {
    const { t } = useT();

    return (
        <Form action="/api/app/auth/sso" after={(data) => (typeof data.redirect === 'string' ? data.redirect : null)}>
            <InputField name="email" label={t('Work email address')} type="email" autoComplete="username" required autoFocus />
            <Submit className="w-full justify-center">{t('Continue')}</Submit>
        </Form>
    );
}
