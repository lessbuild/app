import type { ComponentPropsWithoutRef, ReactNode } from 'react';
import { cn } from '@/lib/cn';

/** A labelled form field with its help text and error, wired up for screen readers. */
export function Field({ id, label, description, error, children, className }: { id: string; label: ReactNode; description?: ReactNode; error?: string; children: ReactNode; className?: string }) {
    return (
        <div className={cn('grid gap-1.5', className)}>
            <label htmlFor={id} className="ui-label">{label}</label>
            {description && <p id={`${id}-description`} className="ui-help">{description}</p>}
            {children}
            {error && <p id={`${id}-error`} className="ui-error" role="alert">{error}</p>}
        </div>
    );
}

/** Describe an input by its help text and error, for aria-describedby. */
export function describedBy(id: string, description?: unknown, error?: unknown): string | undefined {
    return [description ? `${id}-description` : null, error ? `${id}-error` : null].filter(Boolean).join(' ') || undefined;
}

/** Signal's text input. */
export function Input({ className, invalid, ...props }: ComponentPropsWithoutRef<'input'> & { invalid?: boolean }) {
    return <input className={cn('ui-input', className)} aria-invalid={invalid || undefined} {...props} />;
}

/** Signal's multi-line input. */
export function Textarea({ className, invalid, ...props }: ComponentPropsWithoutRef<'textarea'> & { invalid?: boolean }) {
    return <textarea className={cn('ui-input', className)} aria-invalid={invalid || undefined} {...props} />;
}
