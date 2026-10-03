'use client';

import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { createContext, useContext, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { useT } from '@/lib/i18n-client';
import { ensureCsrf, xsrfToken } from '@/lib/client';
import { confirmIdentity } from '@/lib/confirm';
import { flash } from '@/lib/flash';
import { local } from '@/lib/url';

type Errors = Record<string, string[]>;
type FormState = { errors: Errors; busy: boolean };

const FormContext = createContext<FormState>({ errors: {}, busy: false });

/** The errors and busy state of the form a field is in. */
export function useForm(): FormState {
    return useContext(FormContext);
}

/** Laravel's error key for an input name: `services[]` → `services`, `keys[p256dh]` → `keys.p256dh`. */
export function errorKey(name: string): string {
    return name.replace(/\[\]$/, '').replace(/\[([^\]]*)\]/g, '.$1');
}

/** The first error for a field, or for any of its items (`services.0`). */
export function firstError(errors: Errors, key: string): string | undefined {
    return errors[key]?.[0] ?? Object.entries(errors).find(([field]) => field.startsWith(`${key}.`))?.[1][0];
}


/**
 * A form that posts to the API (`/api/app/…`) as form data: field names as Laravel expects them, method spoofing for
 * PUT and DELETE, files included. Laravel's validation errors appear on their fields. On success the page refreshes,
 * or goes where the API's `{ redirect }` says; a download is saved.
 */
export function Form({ action, method = 'POST', className = 'grid gap-5', confirm, onSuccess, after, children, id }: {
    action: string;
    method?: 'POST' | 'PUT' | 'PATCH' | 'DELETE';
    className?: string;
    confirm?: string;
    onSuccess?: (redirect: string | null) => void;
    /**
     * Decide where to go after a successful submit, from the API's answer: a path loads that page in full (as after
     * signing in), `null` stays put without refreshing. Without it, the page refreshes or follows `{ redirect }`.
     */
    after?: (data: Record<string, unknown>) => string | null | undefined;
    children: ReactNode;
    id?: string;
}) {
    const router = useRouter();
    const pathname = usePathname();
    const searchParams = useSearchParams();
    const { t } = useT();
    const form = useRef<HTMLFormElement>(null);
    const [state, setState] = useState<FormState>({ errors: {}, busy: false });

    async function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (state.busy || (confirm && !window.confirm(confirm))) {
            return;
        }
        const body = new FormData(event.currentTarget);
        if (method !== 'POST') {
            body.append('_method', method);
        }
        setState({ errors: {}, busy: true });
        await ensureCsrf();
        const post = () => fetch(action, {
            method: 'POST',
            body,
            credentials: 'same-origin',
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
        });
        let response: Response;
        try {
            response = await post();
            if (response.status === 423) {
                // A sensitive change: confirm it's them, then send the form again.
                if (!(await confirmIdentity())) {
                    setState({ errors: {}, busy: false });
                    return;
                }
                response = await post();
            }
        } catch {
            setState({ errors: { _form: [t('The connection failed. Check you’re online and try again.')] }, busy: false });
            return;
        }

        if (response.status === 422) {
            const payload = (await response.json()) as { errors?: Errors; message?: string };
            setState({ errors: payload.errors ?? { _form: [payload.message ?? t('Check the form and try again.')] }, busy: false });
            requestAnimationFrame(() => form.current?.querySelector<HTMLElement>('[aria-invalid="true"], [data-form-error]')?.focus());
            return;
        }
        if (response.status === 409) {
            // A check sends the person somewhere first (such as setting up a second factor).
            const payload = (await response.json()) as { redirect: string };
            window.location.assign(payload.redirect);
            return;
        }
        if (response.status === 419) {
            // The session expired while the form was open: reload to get a fresh one.
            window.location.reload();
            return;
        }
        if (!response.ok) {
            const payload = (await response.json().catch(() => null)) as { message?: string } | null;
            setState({ errors: { _form: [payload?.message || t('Something went wrong. Try again.')] }, busy: false });
            return;
        }

        const type = response.headers.get('content-type') ?? '';
        if (!type.includes('application/json')) {
            // A download, such as an export: save it.
            const blob = await response.blob();
            const name = /filename="?([^";]+)"?/.exec(response.headers.get('content-disposition') ?? '')?.[1] ?? 'download';
            const link = Object.assign(document.createElement('a'), { href: URL.createObjectURL(blob), download: name });
            link.click();
            URL.revokeObjectURL(link.href);
            setState({ errors: {}, busy: false });
            return;
        }

        const text = await response.text();
        const payload = (text ? JSON.parse(text) : {}) as { redirect?: string } & Record<string, unknown>;
        if (typeof payload.message === 'string') {
            flash(payload.message);
        }
        if (after) {
            const next = after(payload);
            if (typeof next === 'string') {
                window.location.assign(next);
                return;
            }
            if (next === null) {
                setState({ errors: {}, busy: false });
                return;
            }
        }
        setState({ errors: {}, busy: false });
        const target = payload.redirect ? local(payload.redirect) : null;
        onSuccess?.(target);
        const here = pathname + (searchParams.size ? `?${searchParams.toString()}` : '');
        if (target === null || target === here) {
            router.refresh();
        } else {
            router.push(target);
        }
    }

    return (
        <FormContext.Provider value={state}>
            <form ref={form} id={id} className={className} onSubmit={submit} noValidate={false} aria-busy={state.busy || undefined}>
                {state.errors._form && (
                    <div data-form-error tabIndex={-1} role="alert" className="ui-alert ui-alert--danger ui-alert-danger">{state.errors._form[0]}</div>
                )}
                {children}
            </form>
        </FormContext.Provider>
    );
}
