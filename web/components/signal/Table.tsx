import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

/** Signal's table: a scrollable, labelled region with a hidden caption. */
export function Table({ caption, head, framed = true, className, tableClass, children }: { caption: string; head?: ReactNode; framed?: boolean; className?: string; tableClass?: string; children: ReactNode }) {
    return (
        <div role="region" aria-label={caption} tabIndex={0} className={cn('ui-table-wrap', !framed && 'rounded-none border-0', className)}>
            <table className={cn('ui-table', tableClass)}>
                <caption className="sr-only">{caption}</caption>
                {head && <thead>{head}</thead>}
                <tbody>{children}</tbody>
            </table>
        </div>
    );
}
