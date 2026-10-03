'use client';

import { useState } from 'react';
import { useT } from '@/lib/i18n-client';
import type { SiteScore } from '@/lib/types';

type Hover = { category: string; siteKey: string } | null;

/**
 * How the audited site scores in each category against its competitors: an emphasis chart, with the site as an accent
 * bar and each competitor as a grey dot on the same 0–100 scale. Every mark shows its value and site on hover and
 * focus, and the same numbers are in the table below, so nothing depends on colour or hovering.
 */
export function ScoreComparison({ sites }: { sites: SiteScore[] }) {
    const { t } = useT();
    const [hover, setHover] = useState<Hover>(null);
    const site = sites[0];
    const competitors = sites.slice(1);
    if (!site) {
        return null;
    }
    const score = (entry: SiteScore, key: string) => entry.categories.find((category) => category.key === key)?.score;

    return (
        <figure className="grid gap-4">
            <figcaption className="flex flex-wrap items-center justify-between gap-3">
                <span className="text-sm font-extrabold text-ink">{t('Scores by category')}</span>
                {competitors.length > 0 && (
                    <span className="flex flex-wrap items-center gap-4 text-xs font-semibold text-muted" aria-hidden="true">
                        <span className="flex items-center gap-1.5"><span className="h-2.5 w-4 rounded-sm bg-[var(--audit-chart-site)]" />{site.name}</span>
                        <span className="flex items-center gap-1.5"><span className="size-2.5 rounded-full bg-[var(--audit-chart-other)]" />{t('Competitors')}</span>
                    </span>
                )}
            </figcaption>

            <div className="grid gap-3" role="list" aria-label={t('Scores by category')}>
                {site.categories.map((category) => {
                    const marks = sites
                        .map((entry) => ({ entry, value: score(entry, category.key) }))
                        .filter((mark): mark is { entry: SiteScore; value: number } => mark.value !== undefined);
                    const active = hover?.category === category.key ? marks.find((mark) => mark.entry.key === hover.siteKey) : undefined;
                    const focus = (entry: SiteScore) => ({
                        onPointerEnter: () => setHover({ category: category.key, siteKey: entry.key }),
                        onPointerLeave: () => setHover(null),
                        onFocus: () => setHover({ category: category.key, siteKey: entry.key }),
                        onBlur: () => setHover(null),
                    });

                    return (
                        <div key={category.key} role="listitem" className="grid grid-cols-[7.5rem_1fr_2.25rem] items-center gap-3 sm:grid-cols-[9rem_1fr_2.25rem]">
                            <span className="truncate text-sm font-semibold text-muted">{category.label}</span>
                            <div className="relative h-7">
                                {/* Recessive hairlines at 0, 50 and 100. */}
                                {[0, 50, 100].map((tick) => (
                                    <span key={tick} className="absolute inset-y-0 w-px bg-line" style={{ left: `${tick}%` }} aria-hidden="true" />
                                ))}
                                {/* The site: a thin bar from the baseline with a rounded data end, its value at the tip. */}
                                <span
                                    className="absolute left-0 top-1/2 h-2.5 -translate-y-1/2 rounded-r-[4px] bg-[var(--audit-chart-site)]"
                                    style={{ width: `${category.score}%`, filter: active?.entry.key === site.key ? 'brightness(1.12)' : undefined }}
                                    aria-hidden="true"
                                />
                                <button
                                    type="button"
                                    className="absolute left-0 top-1/2 h-6 min-w-6 -translate-y-1/2 cursor-default rounded-sm outline-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus"
                                    style={{ width: `${category.score}%` }}
                                    aria-label={`${category.label}: ${site.name} ${category.score}`}
                                    {...focus(site)}
                                />
                                {/* Competitors: grey dots with a surface ring, on a 24px hit area. */}
                                {marks.slice(1).map(({ entry, value }) => (
                                    <button
                                        key={entry.key}
                                        type="button"
                                        className="absolute top-1/2 grid size-6 -translate-x-1/2 -translate-y-1/2 cursor-default place-items-center rounded-full outline-none focus-visible:outline-2 focus-visible:outline-focus"
                                        style={{ left: `${value}%` }}
                                        aria-label={`${category.label}: ${entry.name} ${value}`}
                                        {...focus(entry)}
                                    >
                                        <span className={`block rounded-full bg-[var(--audit-chart-other)] ring-2 ring-surface ${active?.entry.key === entry.key ? 'size-3' : 'size-2.5'}`} />
                                    </button>
                                ))}
                                {active && <Tooltip left={active.value} value={active.value} name={active.entry.name} own={active.entry.key === site.key} />}
                            </div>
                            {/* The site's value in its own column, so it never collides with the bar end or a dot. */}
                            <span className="text-right text-sm font-extrabold tabular-nums text-ink" aria-hidden="true">{category.score}</span>
                        </div>
                    );
                })}
                <div className="grid grid-cols-[7.5rem_1fr_2.25rem] gap-3 sm:grid-cols-[9rem_1fr_2.25rem]" aria-hidden="true">
                    <span />
                    <div className="relative h-4 text-[11px] tabular-nums text-subtle">
                        <span className="absolute left-0">0</span>
                        <span className="absolute left-1/2 -translate-x-1/2">50</span>
                        <span className="absolute right-0">100</span>
                    </div>
                    <span />
                </div>
            </div>

            <details className="text-sm">
                <summary className="ui-link cursor-pointer text-xs font-bold">{t('Show the scores as a table')}</summary>
                <div className="ui-table-wrap mt-3 overflow-x-auto">
                    <table className="ui-table w-full text-left text-sm">
                        <thead>
                            <tr>
                                <th scope="col">{t('Category')}</th>
                                {sites.map((entry) => <th key={entry.key} scope="col" className="text-right">{entry.name}</th>)}
                            </tr>
                        </thead>
                        <tbody>
                            {site.categories.map((category) => (
                                <tr key={category.key}>
                                    <th scope="row" className="font-semibold">{category.label}</th>
                                    {sites.map((entry) => <td key={entry.key} className="text-right tabular-nums">{score(entry, category.key) ?? '—'}</td>)}
                                </tr>
                            ))}
                            <tr>
                                <th scope="row" className="font-extrabold">{t('Overall')}</th>
                                {sites.map((entry) => <td key={entry.key} className="text-right font-extrabold tabular-nums">{entry.score}</td>)}
                            </tr>
                        </tbody>
                    </table>
                </div>
            </details>
        </figure>
    );
}

/** The hover and focus readout: the value first, then whose it is, keyed with the mark's shape. */
function Tooltip({ left, value, name, own }: { left: number; value: number; name: string; own: boolean }) {
    return (
        <span
            role="tooltip"
            className="pointer-events-none absolute bottom-full z-10 mb-1 flex -translate-x-1/2 items-center gap-2 whitespace-nowrap rounded-control border border-line bg-surface px-2.5 py-1.5 text-xs shadow-panel"
            style={{ left: `${Math.max(8, Math.min(left, 92))}%` }}
        >
            <span className={own ? 'h-0.5 w-3 bg-[var(--audit-chart-site)]' : 'size-2 rounded-full bg-[var(--audit-chart-other)]'} aria-hidden="true" />
            <strong className="font-extrabold tabular-nums text-ink">{value}</strong>
            <span className="text-muted">{name}</span>
        </span>
    );
}
