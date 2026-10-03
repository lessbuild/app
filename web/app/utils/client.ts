// Browser-side calls to Laravel's /api/app: same-origin, with the session cookie and Laravel's CSRF token (read from
// the XSRF-TOKEN cookie Laravel sets, sent back as X-XSRF-TOKEN).

/** A form error from Laravel (HTTP 422), with each field's messages. */
export class ValidationError extends Error {
    /**
     * @param message Laravel's summary of the errors.
     * @param errors Each field's messages, keyed as Laravel keys them (`services.0`).
     */
    constructor(message: string, public readonly errors: Record<string, string[]>) {
        super(message);
    }

    /** Get the first error for a field, or for any field starting with it (such as `competitors`). */
    first(field: string): string | undefined {
        return firstError(this.errors, field);
    }
}

/** The first error for a field, or for any of its items (`services.0`). */
export function firstError(errors: Record<string, string[]>, key: string): string | undefined {
    return errors[key]?.[0] ?? Object.entries(errors).find(([field]) => field.startsWith(`${key}.`))?.[1][0];
}

/** The CSRF token from the XSRF-TOKEN cookie, or an empty string before Laravel has set it. */
export function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match?.[1] ? decodeURIComponent(match[1]) : '';
}

/** Make sure Laravel has given this browser a session and a CSRF token (a first-time visitor has neither yet). */
export async function ensureCsrf(): Promise<void> {
    if (!xsrfToken()) {
        await fetch('/api/app/auth/options', { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    }
}

/** The headers every write to the API sends. */
export function writeHeaders(): Record<string, string> {
    return { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() };
}

/**
 * Send a request to the API and return its JSON. Throws ValidationError for form errors, Error otherwise. When the
 * API wants a recent confirmation of who the person is (423), they're asked, and the request is sent once more.
 */
export async function send<T = unknown>(method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE', path: string, body?: unknown, options: { signedOutRedirect?: boolean; confirmed?: boolean } = {}): Promise<T> {
    if (method !== 'GET') {
        await ensureCsrf();
    }
    const url = path.startsWith('/api/') ? path : `/api/app${path}`;
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: { ...writeHeaders(), 'Content-Type': 'application/json' },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    if (response.status === 422) {
        const payload = (await response.json()) as { message: string; errors: Record<string, string[]> };
        throw new ValidationError(payload.message, payload.errors ?? {});
    }
    if (response.status === 423 && !options.confirmed) {
        if (await confirmIdentity()) {
            return send<T>(method, path, body, { ...options, confirmed: true });
        }
        throw new Error('Not confirmed');
    }
    if ((response.status === 401 || response.status === 419) && options.signedOutRedirect !== false) {
        // Signed out, or the session expired: sign in again and come back.
        window.location.assign(`/login?redirect=${encodeURIComponent(window.location.pathname)}`);
        throw new Error('Signed out');
    }
    if (response.status === 409) {
        const payload = (await response.json()) as { redirect: string };
        window.location.assign(payload.redirect);
        throw new Error('Redirected');
    }
    if (!response.ok) {
        const payload = (await response.json().catch(() => null)) as { message?: string } | null;
        throw new Error(payload?.message || `HTTP ${response.status}`);
    }

    const text = await response.text();
    return (text ? JSON.parse(text) : undefined) as T;
}

/** Only follow redirects back into this app, never to another site. */
export function safeRedirect(target: unknown, fallback = '/dashboard'): string {
    const value = Array.isArray(target) ? target[0] : target;
    return typeof value === 'string' && value.startsWith('/') && !value.startsWith('//') ? value : fallback;
}
