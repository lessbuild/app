import type { ReactNode } from 'react';

/** Signal's settings section: the title and description beside a card on wide screens, above it on phones. */
export function SettingsSection({ id, title, description, footer, children }: { id?: string; title: string; description: string; footer?: ReactNode; children: ReactNode }) {
    return (
        <div id={id} className="grid scroll-mt-6 gap-6 lg:grid-cols-[minmax(0,.85fr)_minmax(0,1.5fr)]">
            <div className="hidden lg:block">
                <div className="px-4 sm:px-0">
                    <h2 className="text-lg font-bold leading-tight text-ink">{title}</h2>
                    <p className="text-sm text-muted">{description}</p>
                </div>
            </div>
            <div className="ui-card ui-responsive-details__content overflow-hidden lg:block">
                <div className="border-b border-line px-4 py-4 lg:hidden">
                    <h2 className="font-bold text-ink">{title}</h2>
                    <p className="mt-1 text-sm text-muted">{description}</p>
                </div>
                <div>{children}</div>
                {footer && <div className="border-t border-line">{footer}</div>}
            </div>
        </div>
    );
}
