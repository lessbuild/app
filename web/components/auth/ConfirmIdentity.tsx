'use client';

import { useCallback, useEffect, useId, useRef, useState } from 'react';
import { Form } from '@/components/form/Form';
import { PasswordField, Submit } from '@/components/form/fields';
import { Icon } from '@/components/signal/Icon';
import { send } from '@/lib/client';
import { setConfirmHandler } from '@/lib/confirm';
import { useT } from '@/lib/i18n-client';
import { confirmWithPasskey, passkeysSupported } from '@/lib/passkeys';

type Me = { hasPassword: boolean; hasPasskeys: boolean; confirmProviders: Array<{ key: string; label: string; url: string }> };

/** The ways to confirm it's you: password, passkey, or a provider you connected. Calls onConfirmed once you have. */
export function ConfirmIdentityOptions({ onConfirmed, returnTo }: { onConfirmed: () => void; returnTo?: string }) {
    const { t } = useT();
    const [me, setMe] = useState<Me | null>(null);
    const [status, setStatus] = useState<string | null>(null);

    useEffect(() => {
        send<Me>('GET', '/api/app/auth/me').then(setMe).catch(() => setMe({ hasPassword: true, hasPasskeys: false, confirmProviders: [] }));
    }, []);

    async function passkey() {
        if (!passkeysSupported()) {
            setStatus(t('This browser does not support passkeys.'));
            return;
        }
        setStatus(t('Waiting for your passkey…'));
        try {
            await confirmWithPasskey();
            onConfirmed();
        } catch {
            setStatus(t('Passkey confirmation could not be completed. Please try again.'));
        }
    }

    if (me === null) {
        return <p className="text-sm text-muted" role="status">{t('Loading…')}</p>;
    }

    return (
        <div className="grid gap-5">
            {me.hasPassword && (
                <Form action="/api/app/auth/user/confirm-password" after={() => { onConfirmed(); return null; }}>
                    <PasswordField name="password" label={t('Password')} autoComplete="current-password" required autoFocus />
                    <Submit className="w-full justify-center">{t('Confirm')}</Submit>
                </Form>
            )}
            {me.hasPasskeys && (
                <div className="grid gap-2">
                    <button type="button" className={`ui-btn ${me.hasPassword ? 'ui-btn-secondary' : 'ui-btn-primary'} w-full justify-center`} onClick={passkey}>{t('Confirm with a passkey')}</button>
                    <p role="status" aria-live="polite" className={status ? "text-center text-sm text-muted" : "sr-only"}>{status}</p>
                </div>
            )}
            {me.confirmProviders.map((provider) => (
                <button
                    key={provider.key}
                    type="button"
                    className="ui-btn ui-btn-secondary w-full justify-center"
                    onClick={async () => {
                        // A round trip through the provider, then back to where the person was.
                        const { redirect } = await send<{ redirect: string }>('POST', provider.url, { redirect: returnTo });
                        window.location.assign(redirect);
                    }}
                >
                    {t('Confirm with :provider', { provider: provider.label })}
                </button>
            ))}
        </div>
    );
}

/**
 * The "Confirm it's you" dialog the app opens when an action needs a recent confirmation (the API's 423). Mounted once
 * in the shell; registers itself as the confirmation handler.
 */
export function ConfirmIdentityDialog() {
    const { t } = useT();
    const dialog = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    const pending = useRef<((confirmed: boolean) => void) | null>(null);
    const [open, setOpen] = useState(false);

    const finish = useCallback((confirmed: boolean) => {
        pending.current?.(confirmed);
        pending.current = null;
        setOpen(false);
        dialog.current?.close();
    }, []);

    useEffect(() => {
        setConfirmHandler(() => new Promise<boolean>((resolve) => {
            pending.current = resolve;
            setOpen(true);
            dialog.current?.showModal();
        }));
        return () => setConfirmHandler(null);
    }, []);

    return (
        <dialog ref={dialog} className="ui-dialog" aria-labelledby={titleId} onCancel={(event) => { event.preventDefault(); finish(false); }}>
            {open && (
                <div data-modal-panel>
                    <header className="flex items-start justify-between gap-4 border-b border-line p-5 sm:p-6">
                        <div className="min-w-0">
                            <h2 id={titleId} className="text-lg font-extrabold text-ink">{t('Confirm it’s you')}</h2>
                            <p className="mt-1 text-sm text-muted">{t('This is a sensitive action. Confirm your identity to continue; you won’t be asked again for a while.')}</p>
                        </div>
                        <button type="button" className="ui-icon-btn" aria-label={t('Cancel')} onClick={() => finish(false)}>
                            <Icon name="close" className="h-5 w-5" />
                        </button>
                    </header>
                    <div className="px-5 py-5 sm:px-6">
                        <ConfirmIdentityOptions onConfirmed={() => finish(true)} returnTo={typeof window !== 'undefined' ? window.location.pathname : undefined} />
                    </div>
                </div>
            )}
        </dialog>
    );
}
