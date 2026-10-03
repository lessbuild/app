'use client';

import type { ComponentPropsWithoutRef, ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { useT } from '@/lib/i18n-client';
import { errorKey, firstError, useForm } from './Form';

/** A field's error from the form it's in, shown as Signal's inline alert. */
export function FieldError({ name, id }: { name: string; id?: string }) {
    const { errors } = useForm();
    const message = firstError(errors, name);
    if (!message) {
        return null;
    }

    return (
        <div id={id} data-form-error className="my-2" aria-live="polite">
            <div className="ui-alert ui-alert--danger ui-alert-danger" role="alert">{message}</div>
        </div>
    );
}

/** The error for an input and the ids that describe it. */
function useFieldState(id: string, name: string, description: unknown, key?: string) {
    const { errors } = useForm();
    const field = key ?? errorKey(name);
    const invalid = firstError(errors, field) !== undefined;
    const describedBy = [description ? `${id}-help` : null, invalid ? `${id}-error` : null].filter(Boolean).join(' ') || undefined;

    return { field, invalid, describedBy };
}

/** Signal's field: the label, the control, its help and its error. */
function FieldShell({ id, label, description, required, hideLabel, field, className, children }: {
    id: string; label: ReactNode; description?: ReactNode; required?: boolean; hideLabel?: boolean; field: string; className?: string; children: ReactNode;
}) {
    return (
        <div className={cn('grid min-w-0 gap-2', className)}>
            <label htmlFor={id} className={cn('ui-label', hideLabel ? 'sr-only' : 'wrap-anywhere')}>
                {label}
                {required && <span className="text-danger" aria-hidden="true">*</span>}
            </label>
            {children}
            {description && <p id={`${id}-help`} className="ui-help wrap-anywhere">{description}</p>}
            <FieldError name={field} id={`${id}-error`} />
        </div>
    );
}

type Common = { name: string; label: ReactNode; description?: ReactNode; hideLabel?: boolean; errorKey?: string; fieldClass?: string };

/** A labelled text input that shows its validation error. */
export function InputField({ name, label, description, hideLabel, errorKey: key, fieldClass, id, className, prefix, suffix, ...input }: Common & ComponentPropsWithoutRef<'input'> & { prefix?: ReactNode; suffix?: ReactNode }) {
    const controlId = id ?? name;
    const { field, invalid, describedBy } = useFieldState(controlId, name, description, key);
    const control = (
        <input id={controlId} name={name} className={cn('ui-input min-w-0 w-full', prefix ? 'rounded-l-none' : null, suffix ? 'rounded-r-none' : null, className)} aria-invalid={invalid} aria-describedby={describedBy} {...input} />
    );

    return (
        <FieldShell id={controlId} label={label} description={description} required={input.required} hideLabel={hideLabel} field={field} className={fieldClass}>
            {prefix || suffix ? <div className="flex min-w-0">{prefix}{control}{suffix}</div> : control}
        </FieldShell>
    );
}

/** A labelled multi-line input. */
export function TextareaField({ name, label, description, hideLabel, errorKey: key, fieldClass, id, className, ...input }: Common & ComponentPropsWithoutRef<'textarea'>) {
    const controlId = id ?? name;
    const { field, invalid, describedBy } = useFieldState(controlId, name, description, key);

    return (
        <FieldShell id={controlId} label={label} description={description} required={input.required} hideLabel={hideLabel} field={field} className={fieldClass}>
            <textarea id={controlId} name={name} className={cn('ui-input min-h-28', className)} aria-invalid={invalid} aria-describedby={describedBy} {...input} />
        </FieldShell>
    );
}

export type Option = { value: string; label: string; disabled?: boolean };

/** A labelled select, from a list of options or <option> children. */
export function SelectField({ name, label, description, hideLabel, errorKey: key, fieldClass, id, className, options, placeholder, children, ...select }: Common & ComponentPropsWithoutRef<'select'> & { options?: Option[]; placeholder?: string }) {
    const controlId = id ?? name;
    const { field, invalid, describedBy } = useFieldState(controlId, name, description, key);

    return (
        <FieldShell id={controlId} label={label} description={description} required={select.required} hideLabel={hideLabel} field={field} className={fieldClass}>
            <select id={controlId} name={name} className={cn('ui-input', className)} aria-invalid={invalid} aria-describedby={describedBy} {...select}>
                {placeholder !== undefined && <option value="">{placeholder}</option>}
                {options?.map((option) => <option key={option.value} value={option.value} disabled={option.disabled}>{option.label}</option>)}
                {children}
            </select>
        </FieldShell>
    );
}

/** A checkbox with its label; `uncheckedValue` sends a value when it's left unticked, like the Blade component. */
export function Checkbox({ name, label, description, errorKey: key, id, value = '1', uncheckedValue, className, ...input }: Omit<Common, 'label'> & { label: ReactNode; uncheckedValue?: string } & ComponentPropsWithoutRef<'input'>) {
    const controlId = id ?? name;
    const { field, invalid, describedBy } = useFieldState(controlId, name, description, key);

    return (
        <div className="grid gap-2">
            {uncheckedValue !== undefined && <input type="hidden" name={name} value={uncheckedValue} />}
            <label className="inline-flex min-h-10 cursor-pointer items-center gap-2 text-sm font-semibold text-ink">
                <input id={controlId} name={name} type="checkbox" value={value} aria-invalid={invalid} aria-describedby={describedBy} className={cn('h-4 w-4 rounded border-line accent-[var(--ui-primary)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-focus', className)} {...input} />
                <span>{label}</span>
            </label>
            {description && <p id={`${controlId}-help`} className="ui-help">{description}</p>}
            <FieldError name={field} id={`${controlId}-error`} />
        </div>
    );
}

/** Signal's choice: a radio or checkbox with a label and help, optionally as a card. */
export function Choice({ id, name, label, description, type = 'checkbox', card = false, value = '1', errorKey: key, children, ...input }: Omit<Common, 'label'> & { id: string; label: ReactNode; type?: 'checkbox' | 'radio'; card?: boolean; children?: ReactNode } & Omit<ComponentPropsWithoutRef<'input'>, 'type' | 'children'>) {
    const { field, invalid, describedBy } = useFieldState(id, name, description, key);

    return (
        <div className="min-w-0">
            <label htmlFor={id} className={card ? 'ui-choice' : 'inline-flex min-h-10 cursor-pointer items-start gap-3 py-1'}>
                <input id={id} name={name} type={type} value={value} aria-invalid={invalid} aria-describedby={describedBy} className="ui-check mt-0.5 shrink-0" {...input} />
                <span className="min-w-0 flex-1 wrap-anywhere">
                    <span className="block text-sm font-semibold text-ink">{label}{input.required && <span className="text-danger" aria-hidden="true">*</span>}</span>
                    {description && <span id={`${id}-help`} className="ui-help block">{description}</span>}
                    {children}
                </span>
            </label>
            <FieldError name={field} id={`${id}-error`} />
        </div>
    );
}

/** The submit button, disabled while the form is sending. */
export function Submit({ variant = 'primary', size, className, children }: { variant?: 'primary' | 'secondary' | 'danger' | 'soft' | 'quiet'; size?: 'sm' | 'lg'; className?: string; children: ReactNode }) {
    const { busy } = useForm();
    const { t } = useT();

    return (
        <button type="submit" disabled={busy} aria-busy={busy || undefined} className={cn('ui-btn', `ui-btn-${variant}`, size && `ui-btn-${size}`, className)}>
            {busy ? t('Working…') : children}
        </button>
    );
}

/** The plan-limit message Laravel returns as the `plan` error, with the way to more. */
export function PlanLimitAlert({ billingUrl }: { billingUrl?: string }) {
    const { errors } = useForm();
    const { t } = useT();
    const message = errors.plan?.[0];
    if (!message) {
        return null;
    }

    return (
        <div className="ui-alert ui-alert--warning ui-alert-warning" role="alert">
            {message} {billingUrl && <a href={billingUrl} className="font-semibold underline">{t('See plans with more')}</a>}
        </div>
    );
}
