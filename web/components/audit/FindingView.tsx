import { Badge } from '@/components/signal/Badge';
import { t, type Translator } from '@/lib/i18n';
import type { Finding, Report } from '@/lib/types';
import { Screenshot } from './Screenshot';

/** Labels for a finding's severity and effort. */
export function findingLabels(i18n: Translator) {
    return {
        severity: { high: t(i18n, 'High impact'), medium: t(i18n, 'Medium impact'), low: t(i18n, 'Low impact') },
        severityTone: { high: 'danger', medium: 'warning', low: 'neutral' } as const,
        effort: { small: t(i18n, 'Small change'), medium: t(i18n, 'Medium change'), large: t(i18n, 'Large change') },
    };
}

/** One finding in full: the evidence with the problem outlined, what to change, and the mock-up of the fix. */
export function FindingView({ i18n, finding, report }: { i18n: Translator; finding: Finding; report: Report }) {
    const labels = findingLabels(i18n);

    return (
        <article className="grid gap-6">
            <div className="flex flex-wrap items-center gap-2">
                <Badge tone={labels.severityTone[finding.severity]}>{labels.severity[finding.severity]}</Badge>
                <Badge>{finding.categoryLabel}</Badge>
                <Badge>{labels.effort[finding.effort]}</Badge>
                {finding.pageUrl && <a href={finding.pageUrl} className="ui-link truncate text-xs" target="_blank" rel="noreferrer noopener">{finding.pageUrl}</a>}
            </div>

            <div className="grid gap-4 sm:grid-cols-2">
                <section className="grid content-start gap-2">
                    <h3 className="text-sm font-extrabold text-ink">{t(i18n, 'What’s wrong')}</h3>
                    <p className="text-sm leading-6 text-muted">{finding.detail}</p>
                </section>
                <section className="grid content-start gap-2">
                    <h3 className="text-sm font-extrabold text-ink">{t(i18n, 'What to change')}</h3>
                    <p className="text-sm leading-6 text-muted">{finding.recommendation}</p>
                    {finding.competitorNote && (
                        <p className="rounded-control bg-surface-muted p-3 text-sm leading-6 text-muted"><strong className="text-ink">{t(i18n, 'Competitors:')}</strong> {finding.competitorNote}</p>
                    )}
                </section>
            </div>

            {(finding.screenshotUrl || finding.mockupUrl) && (
                <div className={`grid gap-4 ${finding.screenshotUrl && finding.mockupUrl ? 'lg:grid-cols-2' : ''}`}>
                    {finding.screenshotUrl && (
                        <section className="grid content-start gap-2">
                            <h3 className="text-xs font-extrabold uppercase tracking-[0.14em] text-subtle">{t(i18n, 'Now')}</h3>
                            <Screenshot src={finding.screenshotUrl} alt={t(i18n, 'The page as the visitor saw it, with the problem outlined')} boxes={finding.boxes} screen={report.screen} />
                        </section>
                    )}
                    {finding.mockupUrl && (
                        <section className="grid content-start gap-2">
                            <h3 className="text-xs font-extrabold uppercase tracking-[0.14em] text-subtle">{t(i18n, 'Suggested')}</h3>
                            <figure className="overflow-hidden rounded-card border border-line bg-surface">
                                {/* eslint-disable-next-line @next/next/no-img-element -- private image behind the session. */}
                                <img src={finding.mockupUrl} alt={t(i18n, 'A mock-up of the section with the change made')} loading="lazy" className="block h-auto w-full" />
                                <figcaption className="border-t border-line px-3 py-2 text-xs text-muted">{t(i18n, 'A mock-up to show the idea, not a finished design.')}</figcaption>
                            </figure>
                        </section>
                    )}
                </div>
            )}
        </article>
    );
}
