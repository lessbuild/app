import { api } from './server';

/** What the sign-in and sign-up pages offer (GET /api/app/auth/options). */
export type SignInOptions = {
    registrationOpen: boolean;
    invitedEmail: string | null;
    socialProviders: Array<{ key: string; label: string; url: string }>;
    error: string | null;
    status: string | null;
    turnstileSiteKey: string | null;
};

/** Load the sign-in options, with an access invitation from the address. */
export function signInOptions(invite?: string): Promise<SignInOptions> {
    return api<SignInOptions>('/auth/options', invite ? { invite } : undefined);
}

/** Only follow redirects back into this app, never to another site. */
export function safeRedirect(target: string | string[] | undefined, fallback = '/dashboard'): string {
    const value = Array.isArray(target) ? target[0] : target;
    return value && value.startsWith('/') && !value.startsWith('//') ? value : fallback;
}
