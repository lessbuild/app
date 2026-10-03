'use client';

import { useRouter } from 'next/navigation';
import { useEffect, useState } from 'react';
import { send } from '@/lib/client';
import { useT } from '@/lib/i18n-client';
import type { Report } from '@/lib/types';

/** While a run is going: what it's done so far, checked every few seconds, then the report once it's finished. */
export function RunProgress({ projectId, report: initial }: { projectId: string; report: Report }) {
    const { t, tc } = useT();
    const router = useRouter();
    const [report, setReport] = useState(initial);

    useEffect(() => {
        const timer = window.setInterval(async () => {
            const next = await send<{ report: Report }>('GET', `/projects/${projectId}/audit/runs/${initial.run.id}`).catch(() => null);
            if (!next) {
                return;
            }
            setReport(next.report);
            if (next.report.run.status === 'done' || next.report.run.status === 'failed') {
                window.clearInterval(timer);
                router.refresh();
            }
        }, 4000);

        return () => window.clearInterval(timer);
    }, [projectId, initial.run.id, router]);

    const latest = report.journeys.at(-1);

    return (
        <div className="ui-card grid gap-5 p-6" role="status" aria-live="polite">
            <div className="flex items-center gap-3">
                <span className="ui-spinner size-5" aria-hidden="true" />
                <h2 className="text-lg font-extrabold text-ink">
                    {report.run.status === 'queued' ? t('Waiting to start…') : t('The visitor is trying your site…')}
                </h2>
            </div>
            <p className="text-sm text-muted">{t('Each task is tried on your site and on every competitor’s, then the findings are written. This usually takes a few minutes; you can leave this page and come back.')}</p>
            <dl className="grid grid-cols-2 gap-3 sm:max-w-md">
                <div className="ui-stat">
                    <dt className="text-xs font-bold text-muted">{t('Journeys')}</dt>
                    <dd className="mt-2 text-2xl font-extrabold text-ink">{report.progress.journeys}</dd>
                </div>
                <div className="ui-stat">
                    <dt className="text-xs font-bold text-muted">{t('Pages visited')}</dt>
                    <dd className="mt-2 text-2xl font-extrabold text-ink">{report.progress.pages}</dd>
                </div>
            </dl>
            {latest && (
                <p className="text-sm text-muted">
                    {t('Latest: :goal on :site', { goal: latest.goal, site: latest.siteName })} · {tc(':count step|:count steps', latest.steps.length)}
                    {latest.steps.at(-1)?.thought ? ` · “${latest.steps.at(-1)?.thought}”` : ''}
                </p>
            )}
        </div>
    );
}
