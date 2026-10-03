import type { Metadata } from 'next';
import Link from 'next/link';
import { AcceptInvitation } from '@/components/auth/AcceptInvitation';
import { AuthFrame } from '@/components/auth/AuthFrame';
import { t } from '@/lib/i18n';
import { guestTranslator } from '@/lib/locale';
import { api } from '@/lib/server';

export const metadata: Metadata = { title: 'Invitation' };

type Invitation = { accountName: string; role: string; email: string; invitedBy: string | null; expiresAt: string };

/** An invitation to join an account: accept it, or sign in or sign up first with the invited address. */
export default async function InvitationPage({ params }: { params: Promise<{ token: string }> }) {
    const { token } = await params;
    const [i18n, data] = await Promise.all([guestTranslator(), api<{ invitation: Invitation | null; signedInAs: string | null }>(`/invitations/${encodeURIComponent(token)}`)]);
    const back = encodeURIComponent(`/invitations/${token}`);

    if (data.invitation === null) {
        return (
            <AuthFrame i18n={i18n} heading={t(i18n, 'This invitation isn’t available')} description={t(i18n, 'It may have expired, been used, or been withdrawn. Ask the person who invited you for a new link.')}>
                <Link href="/dashboard" className="ui-btn ui-btn-secondary w-full justify-center">{t(i18n, 'Go to your projects')}</Link>
            </AuthFrame>
        );
    }
    const invitation = data.invitation;

    return (
        <AuthFrame
            i18n={i18n}
            eyebrow={t(i18n, 'Invitation')}
            heading={t(i18n, 'Join :account', { account: invitation.accountName })}
            description={invitation.invitedBy
                ? t(i18n, ':name invited you to join as :role.', { name: invitation.invitedBy, role: invitation.role })
                : t(i18n, 'You were invited to join as :role.', { role: invitation.role })}
        >
            {data.signedInAs === null ? (
                <div className="grid gap-4">
                    <p className="text-sm text-muted">{t(i18n, 'Sign in or create an account with :email to accept.', { email: invitation.email })}</p>
                    <div className="grid gap-2 sm:grid-cols-2">
                        <Link href={`/login?redirect=${back}`} className="ui-btn ui-btn-primary justify-center">{t(i18n, 'Sign in')}</Link>
                        <Link href={`/register?redirect=${back}`} className="ui-btn ui-btn-secondary justify-center">{t(i18n, 'Create an account')}</Link>
                    </div>
                </div>
            ) : (
                <div className="grid gap-4">
                    {data.signedInAs.toLowerCase() !== invitation.email.toLowerCase() && (
                        <div className="ui-alert ui-alert--warning ui-alert-warning" role="status">
                            {t(i18n, 'You’re signed in as :you, but the invitation was sent to :email.', { you: data.signedInAs, email: invitation.email })}
                        </div>
                    )}
                    <AcceptInvitation token={token} />
                </div>
            )}
        </AuthFrame>
    );
}
