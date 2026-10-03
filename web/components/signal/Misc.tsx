import Link from 'next/link';
import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { Icon } from './Icon';

/** Signal's code block. */
export function CodeBlock({ code, className }: { code: string; className?: string }) {
    return <pre className={cn('ui-code-block', className)}><code>{code}</code></pre>;
}

/** Signal's panel. */
export function Panel({ className, children }: { className?: string; children: ReactNode }) {
    return <div className={cn('ui-panel', className)}>{children}</div>;
}

/** Signal's disclosure: a titled section that opens and closes. */
export function Disclosure({ title, open, className, children }: { title: ReactNode; open?: boolean; className?: string; children: ReactNode }) {
    return (
        <details open={open} className={cn('group rounded-panel border border-line bg-surface', className)}>
            <summary className="flex cursor-pointer list-none items-center justify-between gap-3 rounded-panel px-4 py-3 text-sm font-bold text-ink focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus">
                {title}
                <Icon name="chevron-right" className="h-4 w-4 shrink-0 transition-transform group-open:rotate-90" />
            </summary>
            <div className="border-t border-line p-4">{children}</div>
        </details>
    );
}

/** Signal's progress bar or meter. */
export function Progress({ value, max = 100, label, role = 'progressbar', barClass, className }: { value: number; max?: number; label: string; role?: 'progressbar' | 'meter'; barClass?: string; className?: string }) {
    const maximum = Math.max(1, max);
    const current = Math.min(maximum, Math.max(0, value));

    return (
        <div role={role} aria-label={label} aria-valuemin={0} aria-valuemax={maximum} aria-valuenow={current} className={cn('ui-progress', className)}>
            <span className={barClass} style={{ width: `${Math.round((current / maximum) * 10000) / 100}%` }} />
        </div>
    );
}

/** Signal's status dot. */
export function StatusDot({ size = 'sm', color, className }: { size?: 'sm' | 'lg'; color?: string; className?: string }) {
    return <span className={cn('ui-status-dot', size === 'lg' && 'ui-status-dot-lg', className)} style={color ? ({ '--ui-status-dot': color } as React.CSSProperties) : undefined} />;
}

/** Signal's text link. Internal paths navigate inside the app. */
export function TextLink({ href, variant = 'primary', size = 'md', layout = 'inline', className, children }: {
    href: string; variant?: 'primary' | 'muted' | 'quiet'; size?: 'sm' | 'md' | 'inline' | 'none'; layout?: 'inline' | 'stack' | 'block'; className?: string; children: ReactNode;
}) {
    const variants = { primary: 'font-extrabold text-primary hover:bg-primary-soft', muted: 'font-bold text-muted hover:bg-surface-muted hover:text-ink', quiet: 'font-semibold text-subtle hover:text-ink' };
    const sizes = { sm: 'min-h-9 px-3 text-sm', md: 'min-h-10 px-3 text-sm', inline: 'min-h-0 px-0 text-sm', none: '' };
    const layouts = { inline: 'inline-flex items-center gap-2', stack: 'flex flex-col items-center', block: 'flex items-center' };

    return (
        <Link href={href} className={cn('ui-link rounded-control transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus', layouts[layout], variants[variant], sizes[size], className)}>
            {children}
        </Link>
    );
}

/** Signal's local navigation: a scrolling row of section links. */
export function LocalNav({ label, children }: { label: string; children: ReactNode }) {
    return (
        <nav className="ui-local-nav" aria-label={label}>
            <div className="ui-local-nav__scroll">{children}</div>
        </nav>
    );
}

/**
 * A page's sections as tabs, linked with `?tab=`, as Signal's page tabs: refreshing, bookmarks and redirects after
 * saving open the same tab. The page renders only the current tab's panel.
 */
export function PageTabs({ tabs, current, base, label }: { tabs: Record<string, string>; current: string; base: string; label: string }) {
    return (
        <LocalNav label={label}>
            {Object.entries(tabs).map(([key, title]) => (
                <Link key={key} href={`${base}?tab=${key}`} scroll={false} className="ui-local-nav__link" aria-current={key === current ? 'page' : undefined}>
                    {title}
                </Link>
            ))}
        </LocalNav>
    );
}
