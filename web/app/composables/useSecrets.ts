// Passwords and tokens the API returns once (a new server's root password, a website's database password), kept for
// the page the app goes to next, which shows them until they're dismissed.

/** The one-time secrets waiting to be shown, by name (such as `root` or `database`). */
export function useSecrets() {
    return useState<Record<string, string> | null>('one-time-secrets', () => null);
}

