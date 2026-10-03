'use client';

import { useT } from '@/lib/i18n-client';

/** Sign out through Laravel's logout route, with the CSRF header a plain form can't send, then go to the home page. */
export function SignOutButton({ action }: { action: string }) {
    const { t } = useT();

    async function signOut() {
        const token = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)?.[1];
        await fetch(action, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': token ? decodeURIComponent(token) : '' },
        }).catch(() => null);
        // A full reload, so nothing from the signed-in session stays in memory; the home page is a Laravel page anyway.
        window.location.href = '/';
    }

    return <button type="button" className="topbar-nav-link w-full" onClick={signOut}>{t('Sign out')}</button>;
}
