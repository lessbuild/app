import type { Metadata } from 'next';
import Link from 'next/link';
import { RunBadge } from '@/components/audit/RunBadge';
import { ButtonLink } from '@/components/signal/Button';
import { EmptyState } from '@/components/signal/EmptyState';
import { Icon } from '@/components/signal/Icon';
import { PageHeader } from '@/components/signal/PageHeader';
import { api } from '@/lib/api';
import { projectContext } from '@/lib/context';
import { dateTime, t, tc } from '@/lib/i18n';
import type { AuditListItem, AuditPlan, Goal } from '@/lib/types';

export const metadata: Metadata = { title: 'Audit' };

/** The project's audits: each site with its latest score, and how much of the month's allowance is used. */
export default async function AuditsPage({ params }: { params: Promise<{ project: string }> }) {
    const { project } = await params;
    const [{ i18n }, data] = await Promise.all([
        projectContext(project, 'audit'),
        api<{ audits: AuditListItem[]; plan: AuditPlan; goals: Goal[] }>(`/projects/${project}/audit`),
    ]);
    const base = `/projects/${project}/audit`;
    const canAdd = data.plan.canManage && (data.plan.auditLimit === null || data.audits.length < data.plan.auditLimit);

    return (
        <>
            <PageHeader
                i18n={i18n}
                icon="search"
                eyebrow="Audit"
                title={t(i18n, 'Audits')}
                description={t(i18n, 'Watch a visitor use your site and your competitors’, and see what to improve.')}
                actions={canAdd ? <ButtonLink href={`${base}/new`} variant="primary" scroll={false}><Icon name="plus" className="h-4 w-4" />{t(i18n, 'New audit')}</ButtonLink> : undefined}
                metadata={
                    <span className="text-xs font-semibold text-muted">
                        {data.plan.runsAllowance === null
                            ? tc(i18n, ':count audit run this month|:count audits run this month', data.plan.runsUsed)
                            : t(i18n, ':used of :allowance audits used this month', { used: data.plan.runsUsed, allowance: data.plan.runsAllowance })}
                        {data.plan.tier && ` · ${t(i18n, ':tier plan', { tier: data.plan.tier })}`}
                    </span>
                }
            />

            {data.audits.length === 0 ? (
                <EmptyState
                    icon="search"
                    title={t(i18n, 'No audits yet')}
                    description={t(i18n, 'Add your site and a few competitors. A visitor tries real tasks on each, and the report shows what to improve first.')}
                    action={data.plan.canManage ? <ButtonLink href={`${base}/new`} variant="primary" scroll={false}>{t(i18n, 'Set up your first audit')}</ButtonLink> : undefined}
                />
            ) : (
                <ul className="grid gap-3 md:grid-cols-2">
                    {data.audits.map((audit) => (
                        <li key={audit.id}>
                            <Link href={`${base}/${audit.id}`} className="ui-card ui-card--interactive flex h-full flex-col gap-4 p-5">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <h2 className="truncate text-base font-extrabold text-ink">{audit.name}</h2>
                                        <p className="truncate text-sm text-muted">{audit.url}</p>
                                    </div>
                                    {audit.latestRun && <RunBadge i18n={i18n} run={audit.latestRun} />}
                                </div>
                                <p className="mt-auto flex flex-wrap gap-x-4 gap-y-1 text-xs font-semibold text-muted">
                                    <span>{tc(i18n, ':count competitor|:count competitors', audit.competitors)}</span>
                                    <span>{audit.latestRun ? t(i18n, 'Last run :date', { date: dateTime(i18n, audit.latestRun.createdAt) }) : t(i18n, 'Not run yet')}</span>
                                </p>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
        </>
    );
}
