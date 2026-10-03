import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

export type Tone = 'neutral' | 'accent' | 'info' | 'success' | 'warning' | 'danger';

/** Signal's badge: a short status label. */
export function Badge({ tone = 'neutral', className, children }: { tone?: Tone; className?: string; children: ReactNode }) {
    const signal = tone === 'accent' ? 'primary' : tone === 'neutral' ? 'soft' : tone;

    return <span className={cn('ui-badge', `ui-badge--${tone}`, `ui-badge-${signal}`, className)}>{children}</span>;
}
