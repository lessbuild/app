'use client';

import { useState } from 'react';
import { send } from '@/lib/client';
import { flash } from '@/lib/flash';
import { useT } from '@/lib/i18n-client';
import { local } from '@/lib/url';

/** Create the sample project (made-up data, nothing reaching the outside world) and open it. */
export function SampleProjectButton() {
    const { t } = useT();
    const [busy, setBusy] = useState(false);

    async function create() {
        setBusy(true);
        try {
            const result = await send<{ redirect: string; message: string }>('POST', '/projects/sample');
            flash(result.message, 'info');
            window.location.assign(local(result.redirect));
        } catch {
            setBusy(false);
        }
    }

    return <button type="button" className="ui-btn ui-btn-secondary" onClick={create} disabled={busy} aria-busy={busy || undefined}>{busy ? t('Working…') : t('Explore a sample project')}</button>;
}
