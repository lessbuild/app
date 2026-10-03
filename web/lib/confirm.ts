// When an action needs a recent confirmation of who the person is, the API answers 423. The app's shell registers a
// handler that asks them to confirm (password, passkey or a connected provider) and resolves true once they have, so
// requests can be retried instead of sending the person to another page.

let handler: (() => Promise<boolean>) | null = null;

/** Register the function that asks the person to confirm it's them (the shell does this once). */
export function setConfirmHandler(next: (() => Promise<boolean>) | null): void {
    handler = next;
}

/** Ask the person to confirm it's them; without a handler, fall back to the full confirmation page. */
export async function confirmIdentity(): Promise<boolean> {
    if (handler) {
        return handler();
    }
    window.location.assign(`/user/confirm-password?redirect=${encodeURIComponent(window.location.pathname + window.location.search)}`);
    return false;
}
