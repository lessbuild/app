'use client';

import { useState } from 'react';
import { send } from '@/lib/client';
import { useT } from '@/lib/i18n-client';

/** Resend the verification link, or sign out to use another address. */
export function VerifyEmailActions() {
    const { t } = useT();
    const [state, setState] = useState<'idle' | 'sending' | 'sent' | 'failed'>('idle');

    async function resend() {
        setState('sending');
        try {
            await send('POST', '/api/app/auth/email/verification-notification');
            setState('sent');
        } catch {
            setState('failed');
        }
    }

    async function signOut() {
        await send('POST', '/api/app/auth/logout', undefined, { signedOutRedirect: false }).catch(() => null);
        window.location.assign('/login');
    }

    return (
        <div className="grid gap-4">
            {state === 'sent' && <div className="ui-alert ui-alert--success ui-alert-success" role="status">{t('A new verification link is on its way.')}</div>}
            {state === 'failed' && <div className="ui-alert ui-alert--danger ui-alert-danger" role="alert">{t('The link couldn’t be sent. Wait a minute and try again.')}</div>}
            <div className="flex flex-wrap items-center gap-3">
                <button type="button" className="ui-btn ui-btn-primary" onClick={resend} disabled={state === 'sending'} aria-busy={state === 'sending' || undefined}>{t('Resend link')}</button>
                <button type="button" className="ui-btn ui-btn-quiet" onClick={signOut}>{t('Sign out')}</button>
            </div>
        </div>
    );
}
