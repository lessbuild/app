'use client';

import { useId, useRef, useState, type ReactNode } from 'react';
import { cn } from '@/lib/cn';
import { useT } from '@/lib/i18n-client';
import { Button } from './Button';

export type WizardStep = {
    id: string;
    title: string;
    description?: string;
    content: ReactNode;
    /** Check the step before moving on; return an error message to stay on it. */
    validate?: () => string | null;
};

/**
 * A task split into steps that build on each other: a numbered list of the steps, one step at a time, Back and
 * Continue, and the finishing action on the last step. Focus moves to each new step's heading for screen readers.
 */
export function Wizard({ steps, current, onStepChange, finishLabel, busy, error, onFinish }: {
    steps: WizardStep[];
    current: number;
    onStepChange: (index: number) => void;
    finishLabel: string;
    busy?: boolean;
    error?: string | null;
    onFinish: () => void;
}) {
    const { t } = useT();
    const heading = useRef<HTMLHeadingElement>(null);
    const headingId = useId();
    const [stepError, setStepError] = useState<string | null>(null);
    const step = steps[current];
    const last = current === steps.length - 1;

    function go(index: number) {
        setStepError(null);
        onStepChange(index);
        requestAnimationFrame(() => heading.current?.focus());
    }

    function next() {
        const problem = step?.validate?.() ?? null;
        if (problem) {
            setStepError(problem);
            return;
        }
        if (last) {
            onFinish();
        } else {
            go(current + 1);
        }
    }

    if (!step) {
        return null;
    }

    return (
        <form
            className="grid gap-6"
            aria-labelledby={headingId}
            onSubmit={(event) => {
                event.preventDefault();
                next();
            }}
        >
            <ol className="flex flex-wrap items-center gap-2 text-xs font-bold" aria-label={t('Steps')}>
                {steps.map((item, index) => (
                    <li key={item.id} className="flex items-center gap-2">
                        <button
                            type="button"
                            disabled={index > current || busy}
                            onClick={() => go(index)}
                            className={cn(
                                'flex items-center gap-2 rounded-pill px-2.5 py-1 transition',
                                index === current ? 'bg-primary-soft text-primary' : index < current ? 'text-ink hover:bg-surface-muted' : 'text-subtle',
                            )}
                            aria-current={index === current ? 'step' : undefined}
                        >
                            <span className={cn('grid size-5 place-items-center rounded-full text-[11px]', index < current ? 'bg-primary text-on-primary' : 'border border-current')} aria-hidden="true">
                                {index < current ? '✓' : index + 1}
                            </span>
                            {item.title}
                        </button>
                        {index < steps.length - 1 && <span className="h-px w-4 bg-line" aria-hidden="true" />}
                    </li>
                ))}
            </ol>

            <section className="grid gap-5">
                <div>
                    <p className="ui-eyebrow">{t('Step :current of :total', { current: current + 1, total: steps.length })}</p>
                    <h3 id={headingId} ref={heading} tabIndex={-1} className="mt-1 text-xl font-extrabold tracking-tight text-ink outline-none">{step.title}</h3>
                    {step.description && <p className="mt-1 text-sm text-muted">{step.description}</p>}
                </div>
                {step.content}
                {(stepError || error) && <p className="ui-error" role="alert">{stepError ?? error}</p>}
            </section>

            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-line pt-5">
                <Button variant="quiet" disabled={current === 0 || busy} onClick={() => go(current - 1)}>{t('Back')}</Button>
                <Button type="submit" variant="primary" disabled={busy} aria-busy={busy || undefined}>
                    {busy ? t('Working…') : last ? finishLabel : t('Continue')}
                </Button>
            </div>
        </form>
    );
}
