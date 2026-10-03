// The JSON the sign-in endpoints return.

/** What the sign-in and sign-up pages offer (GET /api/app/auth/options). */
export type SignInOptions = {
    registrationOpen: boolean;
    invitedEmail: string | null;
    socialProviders: Array<{ key: string; label: string; url: string }>;
    error: string | null;
    status: string | null;
    turnstileSiteKey: string | null;
};

/** The signed-in person (GET /api/app/auth/me). */
export type Me = { name: string; email: string; emailVerified: boolean; hasPassword: boolean; hasPasskeys: boolean; locale: string };

/** An invitation to join an account (GET /api/app/invitations/{token}). */
export type Invitation = { accountName: string; role: string; email: string; invitedBy: string | null; expiresAt: string };
