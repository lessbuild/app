'use client';

import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { Form } from '@/components/form/Form';
import { Submit } from '@/components/form/fields';
import { cn } from '@/lib/cn';
import { useT } from '@/lib/i18n-client';
import { Icon } from './Icon';

type Size = 'default' | 'wide' | 'large';

/**
 * A dialog on the page, opened by its button or by `?dialog=<id>` in the address (so it can be linked to, as on the
 * Blade pages). Opening it adds `?dialog=<id>`; closing removes it, so Back closes it too.
 */
export function Dialog({ id, title, description, trigger, size = 'default', bodyClass = 'px-5 py-5 sm:px-6', children }: {
    id: string;
    title: string;
    description?: string;
    trigger: (open: () => void) => ReactNode;
    size?: Size;
    bodyClass?: string;
    children: ReactNode | ((close: () => void) => ReactNode);
}) {
    const { t } = useT();
    const router = useRouter();
    const pathname = usePathname();
    const searchParams = useSearchParams();
    const dialog = useRef<HTMLDialogElement>(null);
    const titleId = useId();
    const open = searchParams.get('dialog') === id;

    const url = (dialogId: string | null) => {
        const params = new URLSearchParams(searchParams.toString());
        if (dialogId) {
            params.set('dialog', dialogId);
        } else {
            params.delete('dialog');
        }
        const text = params.toString();
        return text ? `${pathname}?${text}` : pathname;
    };
    const show = () => router.push(url(id), { scroll: false });
    const close = () => router.replace(url(null), { scroll: false });

    useEffect(() => {
        const element = dialog.current;
        if (open && element && !element.open) {
            element.showModal();
        }
        if (!open && element?.open) {
            element.close();
        }
    }, [open]);

    return (
        <>
            {trigger(show)}
            <dialog
                ref={dialog}
                id={id}
                className={cn('ui-dialog', size === 'wide' && 'ui-dialog-wide', size === 'large' && 'ui-dialog-large')}
                aria-labelledby={titleId}
                onCancel={(event) => {
                    event.preventDefault();
                    close();
                }}
                onClick={(event) => event.target === dialog.current && close()}
            >
                {open && (
                    <div data-modal-panel>
                        <header data-modal-header className="flex items-start justify-between gap-4 border-b border-line p-5 sm:p-6">
                            <div className="min-w-0">
                                <h2 id={titleId} className="text-lg font-extrabold text-ink">{title}</h2>
                                {description && <p className="mt-1 text-sm text-muted">{description}</p>}
                            </div>
                            <button type="button" className="ui-icon-btn" aria-label={t('Close :title', { title })} onClick={close} autoFocus>
                                <Icon name="close" className="h-5 w-5" />
                            </button>
                        </header>
                        <div data-modal-body className={bodyClass}>{typeof children === 'function' ? children(close) : children}</div>
                    </div>
                )}
            </dialog>
        </>
    );
}

/** A dialog holding one form, its submit button at the bottom right; it closes when the form is saved. */
export function FormDialog({ id, title, description, action, method = 'POST', submit, submitVariant = 'primary', trigger, size, formClass = 'grid gap-5', children }: {
    id: string;
    title: string;
    description?: string;
    action: string;
    method?: 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    submit?: string;
    submitVariant?: 'primary' | 'danger' | 'secondary';
    trigger: (open: () => void) => ReactNode;
    size?: Size;
    formClass?: string;
    children: ReactNode;
}) {
    return (
        <Dialog id={id} title={title} description={description} trigger={trigger} size={size}>
            {(close) => (
                <Form action={action} method={method} className={formClass} onSuccess={close}>
                    {children}
                    <div className="flex justify-end gap-2">
                        <Submit variant={submitVariant}>{submit ?? title}</Submit>
                    </div>
                </Form>
            )}
        </Dialog>
    );
}

/** Ask before deleting something, as Signal's delete confirmation does. */
export function DeleteDialog({ id, title, description, action, warning, submitLabel, trigger, children }: {
    id: string;
    title: string;
    description?: string;
    action: string;
    warning?: string;
    submitLabel?: string;
    trigger: (open: () => void) => ReactNode;
    children?: ReactNode;
}) {
    const { t } = useT();

    return (
        <Dialog id={id} title={title} description={description} trigger={trigger} bodyClass="p-0">
            {(close) => (
                <Form action={action} method="DELETE" className="grid" onSuccess={close}>
                    {children}
                    <div className="flex items-start gap-4 px-5 py-5 sm:px-6">
                        <span className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full border border-line bg-surface-muted text-[var(--ui-danger)]" aria-hidden="true">
                            <Icon name="information-circle" className="h-5 w-5" />
                        </span>
                        <p className="text-sm text-muted">{warning ?? t('This action cannot be undone.')}</p>
                    </div>
                    <div className="flex flex-wrap justify-end gap-3 border-t border-line bg-surface-muted px-5 py-4 sm:px-6">
                        <button type="button" className="ui-btn ui-btn-secondary" onClick={close}>{t('Cancel')}</button>
                        <Submit variant="danger">{submitLabel ?? t('Delete')}</Submit>
                    </div>
                </Form>
            )}
        </Dialog>
    );
}
