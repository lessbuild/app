import { cookies, headers } from 'next/headers';
import { notFound, redirect } from 'next/navigation';

// Server-side calls to Laravel's browser API (/api/app), made as the signed-in person: their cookies are forwarded,
// so Laravel's session, policies and plan checks decide what they see, exactly as for the Blade pages.

const base = process.env.LARAVEL_URL ?? 'http://127.0.0.1:8000';

/** Fetch JSON from /api/app. Signed-out people go to sign in; pages they may not see are a 404. */
export async function api<T>(path: string): Promise<T> {
    const incoming = await headers();
    const response = await fetch(`${base}/api/app${path}`, {
        headers: {
            Accept: 'application/json',
            Cookie: (await cookies()).toString(),
            'Accept-Language': incoming.get('accept-language') ?? '',
            'X-Forwarded-For': incoming.get('x-forwarded-for') ?? '',
            'User-Agent': incoming.get('user-agent') ?? 'BuildPusher web',
        },
        cache: 'no-store',
    });
    if (response.status === 401 || response.status === 419) {
        redirect(`/login?redirect=${encodeURIComponent(incoming.get('x-pathname') ?? '/')}`);
    }
    if (response.status === 403 || response.status === 404) {
        notFound();
    }
    if (!response.ok) {
        throw new Error(`GET /api/app${path} answered ${response.status}`);
    }

    return (await response.json()) as T;
}

/** Turn one of Laravel's absolute URLs on this site into a path, so links stay inside the Next.js app. */
export function local(url: string): string {
    try {
        const parsed = new URL(url);
        return parsed.pathname + parsed.search + parsed.hash;
    } catch {
        return url;
    }
}
