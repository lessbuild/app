'use client';

import { useRouter } from 'next/navigation';
import { useEffect, useId, useRef, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { useT } from '@/lib/i18n-client';
import { Icon } from './Icon';

/**
 * A page shown as a modal, for intercepted routes: it has its own URL (a refresh or a shared link opens the full page),
 * and closing it (the button, Escape or the backdrop) goes back to the page underneath.
 */
export function Modal({ title, description, size = 'default', children }: { title: string; description?: string; size?: 'default' | 'wide' | 'large'; children: ReactNode }) {
    const router = useRouter();
    const { t } = useT();
    const dialog = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    const descriptionId = useId();

    useEffect(() => {
        const element = dialog.current;
        if (element && !element.open) {
            element.showModal();
        }
    }, []);

    return (
        <dialog
            ref={dialog}
            className={cn('ui-dialog', size === 'wide' && 'ui-dialog-wide', size === 'large' && 'ui-dialog-large')}
            aria-labelledby={titleId}
            aria-describedby={description ? descriptionId : undefined}
            onCancel={(event) => {
                event.preventDefault();
                router.back();
            }}
            onClick={(event) => {
                if (event.target === dialog.current) {
                    router.back();
                }
            }}
        >
            <div data-modal-panel>
                <header data-modal-header className="flex items-start justify-between gap-4 border-b border-line p-5 sm:p-6">
                    <div className="min-w-0">
                        <h2 id={titleId} className="text-lg font-extrabold text-ink">{title}</h2>
                        {description && <p id={descriptionId} className="mt-1 text-sm text-muted">{description}</p>}
                    </div>
                    <button type="button" className="ui-icon-btn" aria-label={t('Close :title', { title })} onClick={() => router.back()} autoFocus>
                        <Icon name="close" className="h-5 w-5" />
                    </button>
                </header>
                <div data-modal-body className="px-5 py-5 sm:px-6">{children}</div>
            </div>
        </dialog>
    );
}
