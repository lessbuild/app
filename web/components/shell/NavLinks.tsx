'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { cn } from '@/lib/cn';

type Item = { label: string; href: string };

/** Find the link for the current page: the longest link path the page's path starts with. */
function currentHref(items: Item[], pathname: string): string | null {
    let best: string | null = null;
    for (const item of items) {
        const path = item.href.split('?')[0] ?? item.href;
        if ((pathname === path || pathname.startsWith(path.endsWith('/') ? path : `${path}/`)) && (best === null || path.length > best.length)) {
            best = item.href;
        }
    }
    return best;
}

/** A row of topbar links, marking the current one. Links to pages still on Laravel are plain links. */
export function NavLinks({ items, label, className, moved, service }: { items: Item[]; label: string; className?: string; moved: string[]; service?: string }) {
    const pathname = usePathname();
    // Service tabs link to the service's overview; on any of its pages, its tab is the current one.
    const current = (service && items.find((item) => item.href.endsWith(`/services/${service}`) || item.href.endsWith(`/services/${service}/`))?.href) || currentHref(items, pathname);

    return (
        <nav className={cn('ui-horizontal-scroll flex min-w-0 items-center gap-1 overflow-x-auto', className)} aria-label={label}>
            {items.map((item) => {
                const props = { className: 'topbar-nav-link', 'aria-current': item.href === current ? ('page' as const) : undefined };
                return moved.some((prefix) => item.href.includes(prefix)) ? (
                    <Link key={item.href} href={item.href} {...props}>{item.label}</Link>
                ) : (
                    <a key={item.href} href={item.href} {...props}>{item.label}</a>
                );
            })}
        </nav>
    );
}
