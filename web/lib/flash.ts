// A short message to show once the next page has loaded ("Project created."), kept in sessionStorage across the
// navigation.

const KEY = 'buildpusher.flash';

/** Keep a message to show on the next page. */
export function flash(message: string, tone: 'success' | 'info' | 'warning' = 'success'): void {
    try {
        sessionStorage.setItem(KEY, JSON.stringify({ message, tone }));
    } catch {
        // Private browsing without storage: the event below still shows it on this page.
    }
    window.dispatchEvent(new CustomEvent(EVENT, { detail: { message, tone } }));
}

/** The event that tells the toaster on the current page about a new message. */
export const EVENT = 'buildpusher:flash';

/** Take the waiting message, if there is one. */
export function takeFlash(): { message: string; tone: 'success' | 'info' | 'warning' } | null {
    try {
        const value = sessionStorage.getItem(KEY);
        sessionStorage.removeItem(KEY);
        return value ? JSON.parse(value) : null;
    } catch {
        return null;
    }
}
