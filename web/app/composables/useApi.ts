// Reading Laravel's API (/api/app) as the signed-in person. On the server the person's cookies are forwarded (and any
// cookie Laravel refreshes is passed back to the browser), so Laravel's session, policies and plan checks decide what
// they see; in the browser it's a same-origin request.

type Query = Record<string, string | string[] | null | undefined>;

/** An answer from the API that isn't the data: its status and JSON body. */
export class ApiError extends Error {
    /**
     * @param status The HTTP status.
     * @param payload The JSON body, if there was one.
     */
    constructor(public readonly status: number, public readonly payload: { redirect?: string; message?: string } | null) {
        super(`The API answered ${status}`);
    }
}

/** Drop empty values from a query. */
function clean(query: Query | undefined): Record<string, string | string[]> {
    return Object.fromEntries(Object.entries(query ?? {}).filter((entry): entry is [string, string | string[]] => entry[1] !== null && entry[1] !== undefined && entry[1] !== ''));
}

/** The headers that make Laravel answer as the person who asked for the page, for the public host. */
function forwardedHeaders(incoming: Record<string, string | undefined>, ip: string | undefined): Record<string, string> {
    const proto = incoming['x-forwarded-proto'] ?? 'https';

    return {
        Accept: 'application/json',
        Cookie: incoming.cookie ?? '',
        'Accept-Language': incoming['accept-language'] ?? '',
        'User-Agent': incoming['user-agent'] ?? 'BuildPusher web',
        'X-Forwarded-For': incoming['x-forwarded-for'] ?? ip ?? '',
        'X-Forwarded-Host': incoming['x-forwarded-host'] ?? incoming.host ?? '',
        'X-Forwarded-Proto': proto,
        'X-Forwarded-Port': incoming['x-forwarded-port'] ?? (proto === 'http' ? '80' : '443'),
    };
}

/**
 * Make a function that GETs JSON from /api/app, for use later (in useAsyncData or route middleware). Call it in setup
 * or middleware: on the server it captures the request it forwards.
 */
export function useApiReader() {
    const event = import.meta.server ? useRequestEvent() : undefined;
    const headers = event ? forwardedHeaders(useRequestHeaders(), event.node?.req.socket.remoteAddress) : { Accept: 'application/json' };
    const base = import.meta.server ? useRuntimeConfig().laravelUrl : '';

    return async <T>(path: string, query?: Query): Promise<T> => {
        const response = await $fetch.raw<T>(`${base}/api/app${path}`, {
            query: clean(query),
            headers,
            credentials: 'same-origin',
            redirect: 'manual',
            ignoreResponseError: true,
        });
        if (event) {
            for (const cookie of response.headers.getSetCookie()) {
                event.node?.res?.appendHeader('set-cookie', cookie);
            }
        }
        if (response.status >= 300) {
            throw new ApiError(response.status, (response._data as { redirect?: string; message?: string } | undefined) ?? null);
        }

        return response._data as T;
    };
}

/**
 * Where to go when the API won't give a page its data: signed out (or the session expired) to sign in and back;
 * Laravel's check sending the person somewhere first (409) there; a page that needs a recent confirmation (423) to
 * confirm and back; not theirs to see as a 404.
 */
export function apiErrorNavigation(error: unknown, to: { fullPath: string }) {
    if (error instanceof ApiError) {
        if (error.status === 401 || error.status === 419) {
            return navigateTo(`/login?redirect=${encodeURIComponent(to.fullPath)}`);
        }
        if (error.status === 409 && error.payload?.redirect) {
            return navigateTo(local(error.payload.redirect));
        }
        if (error.status === 423) {
            // The page needs a recent confirmation of who the person is: confirm, then come back.
            return navigateTo(`/user/confirm-password?redirect=${encodeURIComponent(to.fullPath)}`);
        }
        if (error.status === 403 || error.status === 404) {
            return abortNavigation(createError({ statusCode: 404, statusMessage: 'Not found', fatal: true }));
        }
    }

    return abortNavigation(createError({ statusCode: 500, statusMessage: 'The API failed', fatal: true }));
}

/**
 * Load a page's data from /api/app: `const { data } = await useApi<Dashboard>('/dashboard', () => ({ activity }))`.
 * A reactive path or query loads again when it changes; refreshPage() loads it again after a change. A page the
 * person can't see becomes a 404; a lost session goes to sign in.
 */
export async function useApi<T>(path: MaybeRefOrGetter<string>, query?: MaybeRefOrGetter<Query | undefined>) {
    const read = useApiReader();
    const route = useRoute();
    // Code after an await inside a composable loses Nuxt's context; navigation and errors run inside it again.
    const nuxtApp = useNuxtApp();
    const key = computed(() => `api:${toValue(path)}?${new URLSearchParams(Object.entries(clean(toValue(query))).flatMap(([name, value]) => (Array.isArray(value) ? value : [value]).map((item) => [name, item]))).toString()}`);
    const { data, error, refresh } = await useAsyncData<T>(key, () => read<T>(toValue(path), toValue(query)), { deep: false });

    const handle = async (problem: unknown) => {
        if (!problem) {
            return;
        }
        const cause = (problem as { cause?: unknown }).cause ?? problem;
        if (cause instanceof ApiError && [401, 409, 419, 423].includes(cause.status)) {
            await nuxtApp.runWithContext(() => apiErrorNavigation(cause, route));
            return;
        }
        throw nuxtApp.runWithContext(() => createError({ statusCode: cause instanceof ApiError && (cause.status === 403 || cause.status === 404) ? 404 : 500, fatal: true }));
    };
    await handle(error.value);
    watch(error, (problem) => handle(problem));

    return { data: data as Ref<T>, refresh };
}

/** Load the current page's data and the shell again, after a change the page should show. */
export async function refreshPage(): Promise<void> {
    await Promise.all([refreshNuxtData(), refreshShell()]);
}
