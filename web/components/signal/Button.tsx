import Link from 'next/link';
import type { ComponentPropsWithoutRef, ReactNode } from 'react';
import { cn } from '@/lib/cn';

type Variant = 'primary' | 'secondary' | 'quiet' | 'danger' | 'outline' | 'soft';
type Size = 'sm' | 'default' | 'lg';

function classes(variant: Variant, size: Size, className?: string): string {
    return cn('ui-btn', `ui-btn-${variant}`, size === 'sm' && 'ui-btn-sm', size === 'lg' && 'ui-btn-lg', className);
}

/** Signal's button, as a <button>. */
export function Button({ variant = 'secondary', size = 'default', className, type = 'button', ...props }: ComponentPropsWithoutRef<'button'> & { variant?: Variant; size?: Size }) {
    return <button type={type} className={classes(variant, size, className)} {...props} />;
}

/** Signal's button, as a link. Internal links go through Next.js so modals and prefetching work. */
export function ButtonLink({ href, variant = 'secondary', size = 'default', className, children, scroll }: { href: string; variant?: Variant; size?: Size; className?: string; children: ReactNode; scroll?: boolean }) {
    return (
        <Link href={href} className={classes(variant, size, className)} scroll={scroll}>
            {children}
        </Link>
    );
}
