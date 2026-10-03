'use client';

import { useRouter } from 'next/navigation';
import { useState } from 'react';
import { Button } from '@/components/signal/Button';
import { Icon } from '@/components/signal/Icon';
import { send, ValidationError } from '@/lib/client';
import { useT } from '@/lib/i18n-client';
import type { RunSummary } from '@/lib/types';

/** Run an audit now, and go to the run, which shows its progress. */
export function RunAuditButton({ projectId, auditId }: { projectId: string; auditId: number }) {
    const { t } = useT();
    const router = useRouter();
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function run() {
        setBusy(true);
        setError(null);
        try {
            const { run } = await send<{ run: RunSummary }>('POST', `/projects/${projectId}/audit/${auditId}/runs`);
            router.push(`/projects/${projectId}/audit/runs/${run.id}`);
        } catch (problem) {
            setError(problem instanceof ValidationError ? (problem.first('audit') ?? problem.message) : t('The audit couldn’t start. Try again.'));
            setBusy(false);
        }
    }

    return (
        <div className="grid justify-items-end gap-1">
            <Button variant="primary" onClick={run} disabled={busy} aria-busy={busy || undefined}>
                <Icon name="refresh" className="h-4 w-4" />
                {busy ? t('Starting…') : t('Run audit')}
            </Button>
            {error && <p className="ui-error max-w-xs text-right" role="alert">{error}</p>}
        </div>
    );
}

/** Delete an audit after asking, then go back to the list. */
export function DeleteAuditButton({ projectId, auditId, name }: { projectId: string; auditId: number; name: string }) {
    const { t } = useT();
    const router = useRouter();
    const [busy, setBusy] = useState(false);

    async function remove() {
        if (!window.confirm(t('Delete :name and all its reports? This can’t be undone.', { name }))) {
            return;
        }
        setBusy(true);
        await send('DELETE', `/projects/${projectId}/audit/${auditId}`).catch(() => setBusy(false));
        router.push(`/projects/${projectId}/audit`);
        router.refresh();
    }

    return <Button variant="danger" size="sm" onClick={remove} disabled={busy}>{t('Delete audit')}</Button>;
}
