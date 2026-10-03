import { cookies, headers } from 'next/headers';
import { notFound, redirect } from 'next/navigation';

// Server-side calls to Laravel's API (/api/app) as the signed-in person: their cookies are forwarded, so Laravel's
// session, policies and plan checks decide what they see.

const base = process.env.LARAVEL_URL ?? 'http://127.0.0.1:8000';

type SearchParams = Record<string, string | string[] | undefined>;

/** Build a query string from a page's search params, keeping repeated keys. */
export function query(searchParams?: SearchParams): string {
    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(searchParams ?? {})) {
        for (const item of Array.isArray(value) ? value : value === undefined ? [] : [value]) {
            params.append(key, item);
        }
    }
    const text = params.toString();
    return text ? `?${text}` : '';
}

/** The headers that make Laravel answer as the signed-in person, for the public host. */
export async function forwardedHeaders(): Promise<Record<string, string>> {
    const incoming = await headers();
    const proto = incoming.get('x-forwarded-proto') ?? 'https';
    return {
        Accept: 'application/json',
        Cookie: (await cookies()).toString(),
        'Accept-Language': incoming.get('accept-language') ?? '',
        'User-Agent': incoming.get('user-agent') ?? 'BuildPusher web',
        'X-Forwarded-For': incoming.get('x-forwarded-for') ?? '',
        'X-Forwarded-Host': incoming.get('x-forwarded-host') ?? incoming.get('host') ?? '',
        'X-Forwarded-Proto': proto,
        'X-Forwarded-Port': incoming.get('x-forwarded-port') ?? (proto === 'http' ? '80' : '443'),
    };
}

/** GET JSON from /api/app. Signed-out people go to sign in; pages they may not see are a 404. */
export async function api<T>(path: string, searchParams?: SearchParams): Promise<T> {
    const incoming = await headers();
    const response = await fetch(`${base}/api/app${path}${query(searchParams)}`, { headers: await forwardedHeaders(), cache: 'no-store', redirect: 'manual' });
    if (response.status === 401 || response.status === 419) {
        redirect(`/login?redirect=${encodeURIComponent(incoming.get('x-pathname') ?? '/')}`);
    }
    if (response.status === 403 || response.status === 404) {
        notFound();
    }
    if (response.status === 409) {
        // The account needs something first (a second factor, a verified email): Laravel says where.
        const payload = (await response.json().catch(() => null)) as { redirect?: string } | null;
        redirect(payload?.redirect ?? '/');
    }
    if (!response.ok) {
        throw new Error(`GET /api/app${path} answered ${response.status}`);
    }

    return (await response.json()) as T;
}
