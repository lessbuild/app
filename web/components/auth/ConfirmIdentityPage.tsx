'use client';

import { ConfirmIdentityOptions } from './ConfirmIdentity';

/** The full-page confirmation (when the app can't show the dialog): confirm, then go back. */
export function ConfirmIdentityPage({ redirect }: { redirect: string }) {
    return <ConfirmIdentityOptions returnTo={redirect} onConfirmed={() => window.location.assign(redirect)} />;
}
