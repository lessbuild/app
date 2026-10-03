import type { Metadata } from 'next';
import Link from 'next/link';
import { RunAuditButton } from '@/components/audit/AuditActions';
import { findingLabels } from '@/components/audit/FindingView';
import { RunProgress } from '@/components/audit/RunProgress';
import { ScoreComparison } from '@/components/audit/ScoreComparison';
import { ScoreTiles } from '@/components/audit/ScoreTiles';
import { Alert } from '@/components/signal/Alert';
import { Badge } from '@/components/signal/Badge';
import { Card } from '@/components/signal/Card';
import { PageHeader } from '@/components/signal/PageHeader';
import { projectContext } from '@/lib/context';
import { dateTime, t, tc } from '@/lib/i18n';
import { runReport } from '@/lib/report';

export const metadata: Metadata = { title: 'Audit report' };

/** A run's report: progress while it runs; then the scores against competitors, what to fix first, and every journey. */
export default async function ReportPage({ params }: { params: Promise<{ project: string; run: string }> }) {
    const { project, run } = await params;
    const [{ i18n, shell }, report] = await Promise.all([projectContext(project, 'audit'), runReport(project, run)]);
    const base = `/projects/${project}/audit`;
    const labels = findingLabels(i18n);
    const running = report.run.status === 'queued' || report.run.status === 'running';
    const sites = [...new Set(report.journeys.map((journey) => journey.siteKey))];

    return (
        <>
            <PageHeader
                i18n={i18n}
                icon="search"
                title={t(i18n, 'Report from :date', { date: dateTime(i18n, report.run.createdAt) })}
                description={report.audit.url}
                breadcrumbs={[{ label: t(i18n, 'Audits'), href: base }, { label: report.audit.name, href: `${base}/${report.audit.id}` }]}
                actions={!running && shell.project ? <RunAuditButton projectId={project} auditId={report.audit.id} /> : undefined}
            />

            {running && <RunProgress projectId={project} report={report} />}

            {report.run.status === 'failed' && (
                <Alert tone="danger" role="alert">
                    <strong>{t(i18n, 'The audit didn’t finish.')}</strong> {report.run.error}
                </Alert>
            )}

            {report.run.status === 'done' && (
                <>
                    <ScoreTiles i18n={i18n} sites={report.sites} />

                    {report.summary && (
                        <Card as="section" className="grid gap-2 p-5 sm:p-6" aria-labelledby="summary-heading">
                            <h2 id="summary-heading" className="text-lg font-extrabold text-ink">{t(i18n, 'Summary')}</h2>
                            <p className="max-w-3xl text-sm leading-7 text-muted">{report.summary}</p>
                        </Card>
                    )}

                    <Card as="section" className="p-5 sm:p-6" aria-label={t(i18n, 'Scores by category')}>
                        <ScoreComparison sites={report.sites} />
                    </Card>

                    <section className="grid gap-3" aria-labelledby="findings-heading">
                        <div className="flex flex-wrap items-baseline justify-between gap-2">
                            <h2 id="findings-heading" className="text-lg font-extrabold text-ink">{t(i18n, 'What to improve')}</h2>
                            <p className="text-xs text-muted">{tc(i18n, ':count finding, most important first|:count findings, most important first', report.findings.length)}</p>
                        </div>
                        <ol className="grid gap-3 md:grid-cols-2">
                            {report.findings.map((finding) => (
                                <li key={finding.id}>
                                    <Link href={`${base}/runs/${run}/findings/${finding.id}`} scroll={false} className="ui-card ui-card--interactive flex h-full gap-4 p-4">
                                        {finding.screenshotUrl && (
                                            // eslint-disable-next-line @next/next/no-img-element -- private thumbnail behind the session.
                                            <img src={finding.screenshotUrl} alt="" width={128} height={80} loading="lazy" className="hidden h-20 w-32 shrink-0 rounded-control border border-line object-cover object-top sm:block" />
                                        )}
                                        <div className="grid min-w-0 content-start gap-2">
                                            <div className="flex flex-wrap gap-1.5">
                                                <Badge tone={labels.severityTone[finding.severity]}>{labels.severity[finding.severity]}</Badge>
                                                <Badge>{finding.categoryLabel}</Badge>
                                                {finding.mockupUrl && <Badge tone="info">{t(i18n, 'Mock-up')}</Badge>}
                                            </div>
                                            <h3 className="text-sm font-extrabold text-ink">{finding.title}</h3>
                                            <p className="line-clamp-2 text-xs leading-5 text-muted">{finding.recommendation}</p>
                                        </div>
                                    </Link>
                                </li>
                            ))}
                        </ol>
                    </section>
                </>
            )}

            {report.journeys.length > 0 && (
                <section className="grid gap-3" aria-labelledby="journeys-heading">
                    <h2 id="journeys-heading" className="text-lg font-extrabold text-ink">{t(i18n, 'Journeys')}</h2>
                    <div className="ui-table-wrap overflow-x-auto">
                        <table className="ui-table w-full text-left text-sm">
                            <thead>
                                <tr>
                                    <th scope="col">{t(i18n, 'Task')}</th>
                                    {sites.map((key) => <th key={key} scope="col">{report.journeys.find((journey) => journey.siteKey === key)?.siteName}</th>)}
                                </tr>
                            </thead>
                            <tbody>
                                {[...new Set(report.journeys.map((journey) => journey.goal))].map((goal) => (
                                    <tr key={goal}>
                                        <td className="font-semibold text-ink">{goal}</td>
                                        {sites.map((key) => {
                                            const journey = report.journeys.find((item) => item.goal === goal && item.siteKey === key);
                                            return (
                                                <td key={key}>
                                                    {journey ? (
                                                        <Link href={`${base}/runs/${run}/journeys/${journey.id}`} scroll={false} className="ui-link">
                                                            {journey.outcomeLabel} · {tc(i18n, ':count step|:count steps', journey.stepsCount)}
                                                        </Link>
                                                    ) : '—'}
                                                </td>
                                            );
                                        })}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}
        </>
    );
}
