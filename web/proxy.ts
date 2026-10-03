import { NextResponse, type NextRequest } from 'next/server';

/** Pass the page's path to server components (for the sign-in redirect), and never let pages be cached by proxies. */
export function proxy(request: NextRequest) {
    const forwarded = new Headers(request.headers);
    forwarded.set('x-pathname', request.nextUrl.pathname + request.nextUrl.search);

    return NextResponse.next({ request: { headers: forwarded } });
}

export const config = {
    matcher: ['/projects/:path*'],
};
