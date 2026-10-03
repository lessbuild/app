'use client';

import Link from 'next/link';
import { useEffect, useState } from 'react';
import { Badge, type Tone } from '@/components/signal/Badge';
import { Icon } from '@/components/signal/Icon';
import { send } from '@/lib/client';
import { useT } from '@/lib/i18n-client';
import type { ActivityItem, Dashboard } from '@/lib/projects';
import { local } from '@/lib/url';

/** How long ago something happened, in words, for the person's language. */
function ago(iso: string, locale: string): string {
    const seconds = Math.round((new Date(iso).getTime() - Date.now()) / 1000);
    const units: Array<[Intl.RelativeTimeFormatUnit, number]> = [['day', 86400], ['hour', 3600], ['minute', 60]];
    const format = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
    for (const [unit, size] of units) {
        if (Math.abs(seconds) >= size) {
            return format.format(Math.round(seconds / size), unit);
        }
    }
    return format.format(seconds, 'second');
}

/**
 * The team's recent deploys, incidents and changes, filtered by kind, refreshed every 15 seconds so a deploy's outcome
 * appears as it lands.
 */
export function ActivityFeed({ initial, kind, kinds }: { initial: ActivityItem[]; kind: string | null; kinds: Record<string, string> }) {
    const { t, i18n } = useT();
    const [items, setItems] = useState(initial);

    useEffect(() => {
        const timer = window.setInterval(async () => {
            const data = await send<Dashboard>('GET', `/dashboard${kind ? `?activity=${kind}` : ''}`).catch(() => null);
            if (data) {
                setItems(data.activity);
            }
        }, 15000);
        return () => window.clearInterval(timer);
    }, [kind]);

    return (
        <section className="ui-panel grid gap-4 p-5" aria-labelledby="activity-heading">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 id="activity-heading" className="text-base font-extrabold text-ink">{t('Recent activity')}</h2>
                <nav className="flex flex-wrap gap-1 text-xs font-bold" aria-label={t('Filter activity')}>
                    {[['', t('All')], ...Object.entries(kinds)].map(([key, label]) => {
                        const current = (kind ?? '') === key;
                        return (
                            <Link key={key} href={key ? `/dashboard?activity=${key}` : '/dashboard'} scroll={false} aria-current={current ? 'page' : undefined}
                                className={`rounded-full px-2.5 py-1 ${current ? 'bg-primary-soft text-primary' : 'text-muted hover:text-ink'}`}>
                                {label}
                            </Link>
                        );
                    })}
                </nav>
            </div>
            <div aria-live="polite">
                {items.length === 0 ? (
                    <p className="py-3 text-sm text-muted">{t('Nothing yet. Deploys, incidents and changes across your projects show up here.')}</p>
                ) : (
                    <ul>
                        {items.map((item, index) => (
                            <li key={`${item.kind}-${item.at}-${index}`} className={`flex items-start gap-3 py-3 text-sm ${index > 0 ? 'border-t border-line' : ''}`}>
                                <span className="mt-0.5 grid size-8 shrink-0 place-items-center rounded-full bg-surface-muted text-muted" aria-hidden="true">
                                    <Icon name={item.icon} className="size-4" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <p className="font-semibold text-ink">
                                        {item.url ? <Link href={local(item.url)} className="hover:text-primary hover:underline">{item.title}</Link> : item.title}
                                    </p>
                                    <p className="mt-0.5 text-xs text-muted">
                                        {[item.project, item.actor].filter(Boolean).join(' · ')}
                                        {(item.project || item.actor) && ' · '}
                                        <time dateTime={item.at} title={new Date(item.at).toLocaleString(i18n.locale)}>{ago(item.at, i18n.locale)}</time>
                                    </p>
                                </div>
                                <Badge tone={(item.tone as Tone) ?? 'neutral'}>{item.outcome}</Badge>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </section>
    );
}
