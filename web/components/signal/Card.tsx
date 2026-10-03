import type { ElementType, ReactNode } from 'react';
import { cn } from '@/lib/cn';

/** Signal's card: a surface for one piece of content. */
export function Card({ as: Tag = 'div', className, children, ...rest }: { as?: ElementType; className?: string; children: ReactNode; [attribute: `aria-${string}`]: string | undefined }) {
    return (
        <Tag className={cn('ui-card', className)} {...rest}>
            {children}
        </Tag>
    );
}
