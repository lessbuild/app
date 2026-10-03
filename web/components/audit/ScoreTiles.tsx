import { t, type Translator } from '@/lib/i18n';
import type { SiteScore } from '@/lib/types';
import { cn } from '@/lib/cn';
import { scoreTone } from './RunBadge';

/** One tile per site with its overall score; the audited site first and emphasised, each with a word for the score. */
export function ScoreTiles({ i18n, sites }: { i18n: Translator; sites: SiteScore[] }) {
    const words = { success: t(i18n, 'Good'), warning: t(i18n, 'Fair'), danger: t(i18n, 'Poor') } as Record<string, string>;
    const best = Math.max(...sites.slice(1).map((site) => site.score));

    return (
        <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            {sites.map((site, index) => (
                <div key={site.key} className={cn('ui-stat', index === 0 && 'border-[var(--audit-chart-site)] ring-1 ring-[var(--audit-chart-site)]')}>
                    <dt className="flex items-center justify-between gap-2 text-xs font-bold text-muted">
                        <span className="truncate">{index === 0 ? t(i18n, ':name (your site)', { name: site.name }) : site.name}</span>
                        <span className={`ui-badge ui-badge-${scoreTone(site.score)}`}>{words[scoreTone(site.score)]}</span>
                    </dt>
                    <dd className="mt-3 text-4xl font-extrabold tracking-tight text-ink">{site.score}</dd>
                    {index === 0 && sites.length > 1 && (
                        <dd className="mt-1 text-xs text-muted">
                            {site.score >= best ? t(i18n, 'Ahead of every competitor') : t(i18n, ':points points behind the best competitor', { points: best - site.score })}
                        </dd>
                    )}
                </div>
            ))}
        </dl>
    );
}
