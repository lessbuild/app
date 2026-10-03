import type { ReactNode } from 'react';
import { Icon } from './Icon';

/** Signal's empty state: what goes here, and how to add the first one. */
export function EmptyState({ title, description, icon = 'information-circle', action }: { title: string; description?: string; icon?: string; action?: ReactNode }) {
    return (
        <div className="ui-card p-8 text-center" data-ui-feedback="empty">
            <div className="mx-auto max-w-2xl">
                <span className="mx-auto grid h-11 w-11 place-items-center rounded-card bg-primary-soft text-[var(--ui-primary)]">
                    <Icon name={icon} className="h-5 w-5" />
                </span>
                <h2 className="mt-4 text-base font-extrabold text-ink">{title}</h2>
                {description && <p className="mt-2 text-sm leading-6 text-muted">{description}</p>}
                {action && <div className="mt-6 flex flex-wrap justify-center gap-2">{action}</div>}
            </div>
        </div>
    );
}
