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

/**
 * Copy text to the clipboard and say whether it worked, asking people to copy it by hand when the browser refuses.
 *
 * @param text What to copy.
 * @param message What to say once it's copied; "Copied" when not given.
 */
export async function copyText(text: string, message?: string): Promise<void> {
    const { t } = useT();
    const done = await navigator.clipboard.writeText(text).then(() => true, () => false);
    flash(done ? (message ?? t('Copied')) : t('Copy it by hand; your browser didn’t allow copying.'), done ? 'success' : 'warning');
}
