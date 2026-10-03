import type { Metadata } from 'next';
import Link from 'next/link';
import { DeleteAuditButton, RunAuditButton } from '@/components/audit/AuditActions';
import { RunBadge } from '@/components/audit/RunBadge';
import { ButtonLink } from '@/components/signal/Button';
import { Card } from '@/components/signal/Card';
import { EmptyState } from '@/components/signal/EmptyState';
import { PageHeader } from '@/components/signal/PageHeader';
import { api } from '@/lib/api';
import { projectContext } from '@/lib/context';
import { dateTime, t } from '@/lib/i18n';
import type { AuditDetail } from '@/lib/types';

export const metadata: Metadata = { title: 'Audit' };

/** One audit: what it checks, and its runs. */
export default async function AuditPage({ params }: { params: Promise<{ project: string; audit: string }> }) {
    const { project, audit: id } = await params;
    const [{ i18n }, { audit }] = await Promise.all([projectContext(project, 'audit'), api<{ audit: AuditDetail }>(`/projects/${project}/audit/${id}`)]);
    const base = `/projects/${project}/audit`;
    const schedules: Record<string, string> = { none: t(i18n, 'Only when I run it'), monthly: t(i18n, 'Every month'), weekly: t(i18n, 'Every week') };

    return (
        <>
            <PageHeader
                i18n={i18n}
                icon="search"
                title={audit.name}
                description={audit.url}
                breadcrumbs={[{ label: t(i18n, 'Audits'), href: base }]}
                actions={audit.plan.canManage ? (
                    <>
                        <ButtonLink href={`${base}/${audit.id}/edit`} scroll={false}>{t(i18n, 'Edit')}</ButtonLink>
                        <RunAuditButton projectId={project} auditId={audit.id} />
                    </>
                ) : undefined}
            />

            <div className="grid gap-6 lg:grid-cols-[1fr_20rem]">
                <section aria-labelledby="runs-heading" className="grid content-start gap-3">
                    <h2 id="runs-heading" className="text-lg font-extrabold text-ink">{t(i18n, 'Reports')}</h2>
                    {audit.runs.length === 0 ? (
                        <EmptyState icon="clock" title={t(i18n, 'Not run yet')} description={t(i18n, 'Run the audit to get its first report.')} />
                    ) : (
                        <ul className="grid gap-2">
                            {audit.runs.map((run) => (
                                <li key={run.id}>
                                    <Link href={`${base}/runs/${run.id}`} className="ui-card ui-card--interactive flex flex-wrap items-center justify-between gap-3 p-4">
                                        <span className="grid">
                                            <span className="text-sm font-bold text-ink">{dateTime(i18n, run.createdAt)}</span>
                                            <span className="text-xs text-muted">{run.trigger === 'scheduled' ? t(i18n, 'Scheduled') : t(i18n, 'Run by hand')}{run.error ? ` · ${run.error}` : ''}</span>
                                        </span>
                                        <RunBadge i18n={i18n} run={run} />
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <aside className="grid content-start gap-4">
                    <Card className="grid gap-3 p-5">
                        <h2 className="text-sm font-extrabold text-ink">{t(i18n, 'Tasks')}</h2>
                        <ul className="grid gap-1.5 text-sm text-muted">
                            {audit.journeys.map((journey) => <li key={journey.key + journey.goal}>{journey.label}</li>)}
                        </ul>
                    </Card>
                    <Card className="grid gap-3 p-5">
                        <h2 className="text-sm font-extrabold text-ink">{t(i18n, 'Competitors')}</h2>
                        {audit.competitors.length === 0 ? (
                            <p className="text-sm text-muted">{t(i18n, 'None yet.')}</p>
                        ) : (
                            <ul className="grid gap-2 text-sm">
                                {audit.competitors.map((competitor) => (
                                    <li key={competitor.url} className="min-w-0">
                                        <p className="truncate font-semibold text-ink">{competitor.name}</p>
                                        <p className="truncate text-xs text-muted">{competitor.url}</p>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </Card>
                    <Card className="grid gap-2 p-5 text-sm">
                        <h2 className="text-sm font-extrabold text-ink">{t(i18n, 'Schedule')}</h2>
                        <p className="text-muted">{schedules[audit.schedule] ?? audit.schedule}</p>
                        {audit.nextRunAt && <p className="text-xs text-muted">{t(i18n, 'Next run :date', { date: dateTime(i18n, audit.nextRunAt) })}</p>}
                    </Card>
                    {audit.plan.canManage && <div><DeleteAuditButton projectId={project} auditId={audit.id} name={audit.name} /></div>}
                </aside>
            </div>
        </>
    );
}
