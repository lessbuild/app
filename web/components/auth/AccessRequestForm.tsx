'use client';

import { useState } from 'react';
import { Form } from '@/components/form/Form';
import { InputField, SelectField, Submit, TextareaField } from '@/components/form/fields';
import { useT } from '@/lib/i18n-client';

/** Ask for access while sign-up is closed; then say a receipt is on its way. */
export function AccessRequestForm({ teamSizes }: { teamSizes: string[] }) {
    const { t } = useT();
    const [message, setMessage] = useState<string | null>(null);

    if (message) {
        return <div className="ui-alert ui-alert--success ui-alert-success" role="status">{message}</div>;
    }

    return (
        <Form action="/api/app/access-requests" after={(data) => { setMessage(typeof data.message === 'string' ? data.message : t('Thanks. We’ve emailed you a receipt and will be in touch.')); return null; }}>
            <InputField name="name" label={t('Your name')} autoComplete="name" maxLength={120} required autoFocus />
            <InputField name="email" label={t('Work email')} type="email" autoComplete="email" maxLength={255} required />
            <InputField name="company" label={t('Company (optional)')} autoComplete="organization" maxLength={120} />
            <SelectField name="team_size" label={t('Team size')} placeholder={t('Rather not say')} options={teamSizes.map((size) => ({ value: size, label: size }))} />
            <TextareaField name="use_case" label={t('What would you use it for?')} rows={4} maxLength={2000} required />
            <Submit className="w-full justify-center">{t('Request access')}</Submit>
        </Form>
    );
}
