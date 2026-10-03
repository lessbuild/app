// Browser-side calls to Laravel's /api/app: same-origin, with the session cookie and Laravel's CSRF token (read from
// the XSRF-TOKEN cookie Laravel sets, sent back as X-XSRF-TOKEN).

/** A form error from Laravel (HTTP 422), with each field's messages. */
export class ValidationError extends Error {
    constructor(message: string, public readonly errors: Record<string, string[]>) {
        super(message);
    }

    /** Get the first error for a field, or for any field starting with a prefix (such as `competitors`). */
    first(field: string): string | undefined {
        return this.errors[field]?.[0] ?? Object.entries(this.errors).find(([key]) => key.startsWith(`${field}.`))?.[1][0];
    }
}

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/);
    return match?.[1] ? decodeURIComponent(match[1]) : '';
}

/** Send a request to /api/app and return its JSON. Throws ValidationError for form errors, Error otherwise. */
export async function send<T = unknown>(method: 'GET' | 'POST' | 'PUT' | 'DELETE', path: string, body?: unknown): Promise<T> {
    const response = await fetch(`/api/app${path}`, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    if (response.status === 422) {
        const payload = (await response.json()) as { message: string; errors: Record<string, string[]> };
        throw new ValidationError(payload.message, payload.errors ?? {});
    }
    if (response.status === 401 || response.status === 419) {
        // Signed out, or the session expired: sign in again and come back. Sign-in is still a Laravel page, so this is a
        // full navigation rather than a router push.
        // eslint-disable-next-line @next/next/no-location-assign-relative-destination
        window.location.href = `/login?redirect=${encodeURIComponent(window.location.pathname)}`;
        throw new Error('Signed out');
    }
    if (!response.ok) {
        const payload = (await response.json().catch(() => null)) as { message?: string } | null;
        throw new Error(payload?.message || `HTTP ${response.status}`);
    }

    return (response.status === 204 ? undefined : await response.json()) as T;
}
