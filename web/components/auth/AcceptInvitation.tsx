'use client';

import { Form } from '@/components/form/Form';
import { Submit } from '@/components/form/fields';
import { useT } from '@/lib/i18n-client';

/** Accept an invitation and go to the account's projects. */
export function AcceptInvitation({ token }: { token: string }) {
    const { t } = useT();

    return (
        <Form action={`/api/app/invitations/${token}`} after={(data) => (typeof data.redirect === 'string' ? data.redirect : '/dashboard')}>
            <Submit className="w-full justify-center">{t('Accept and join')}</Submit>
        </Form>
    );
}
