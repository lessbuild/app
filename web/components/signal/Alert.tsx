import type { ReactNode } from 'react';
import { cn } from '@/lib/cn';

/** Signal's alert: a message about the page or an action. */
export function Alert({ tone = 'info', role, className, children }: { tone?: 'info' | 'success' | 'warning' | 'danger'; role?: 'status' | 'alert'; className?: string; children: ReactNode }) {
    return (
        <div data-ui-feedback="alert" role={role} className={cn('ui-alert', `ui-alert--${tone}`, tone !== 'info' && `ui-alert-${tone}`, className)}>
            {children}
        </div>
    );
}
