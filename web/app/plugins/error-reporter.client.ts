// Reports the app's own JavaScript errors into the platform's Monitoring (POST /_errors), so broken pages show up like
// server errors do. Only errors from this site's own scripts are sent (not browser extensions), at most five per page
// load.

export default defineNuxtPlugin(() => {
    let sent = 0;

    /** Send one error, with the page it happened on. */
    const report = (error: { message: string; source?: string | null; line?: number | null; column?: number | null; stack?: string | null }) => {
        if (sent >= 5 || xsrfToken() === '') {
            return;
        }
        sent += 1;
        fetch('/_errors', {
            method: 'POST',
            keepalive: true,
            credentials: 'same-origin',
            headers: { ...writeHeaders(), 'Content-Type': 'application/json' },
            body: JSON.stringify({ ...error, page: location.href.split('#')[0] }),
        }).catch(() => {});
    };

    window.addEventListener('error', (event) => {
        if (!event.filename || !event.filename.startsWith(location.origin)) {
            return;
        }
        report({
            message: String(event.message || 'Script error').slice(0, 1000),
            source: event.filename.slice(0, 2048),
            line: event.lineno || null,
            column: event.colno || null,
            stack: event.error?.stack ? String(event.error.stack).slice(0, 8000) : null,
        });
    });

    window.addEventListener('unhandledrejection', (event) => {
        const reason = event.reason as { message?: unknown; stack?: unknown } | undefined;
        const stack = reason?.stack ? String(reason.stack) : '';
        // Skip rejections whose stack points only at other origins (extensions, third-party widgets).
        if (stack && !stack.includes(location.origin)) {
            return;
        }
        report({ message: `Unhandled promise rejection: ${String(reason?.message ?? reason).slice(0, 950)}`, stack: stack ? stack.slice(0, 8000) : null });
    });
});
