<?php

declare(strict_types=1);

namespace App\Domain\Audit\Enums;

enum AuditAction: string
{
    case AccountCreated = 'account.created';
    case MemberInvited = 'member.invited';
    case InvitationRevoked = 'invitation.revoked';
    case InvitationAccepted = 'invitation.accepted';
    case MemberRemoved = 'member.removed';
    case MemberRoleChanged = 'member.role_changed';
    case ProfileUpdated = 'profile.updated';
    case PasswordChanged = 'password.changed';
    case TwoFactorEnabled = 'two_factor.enabled';
    case TwoFactorDisabled = 'two_factor.disabled';
    case RecoveryCodesRegenerated = 'two_factor.recovery_codes';
    case PasskeyAdded = 'passkey.added';
    case PasskeyRemoved = 'passkey.removed';
    case SocialConnected = 'social.connected';
    case SocialDisconnected = 'social.disconnected';
    case BrowsersSignedOut = 'sessions.signed_out';

    /** @param array<string, mixed> $context */
    public function describe(array $context): string
    {
        $value = fn (string $key): string => is_scalar($context[$key] ?? null) ? (string) $context[$key] : '?';

        return match ($this) {
            self::AccountCreated => __('Created the account :name', ['name' => $value('name')]),
            self::MemberInvited => __('Invited :email as :role', ['email' => $value('email'), 'role' => $value('role')]),
            self::InvitationRevoked => __('Revoked the invitation for :email', ['email' => $value('email')]),
            self::InvitationAccepted => __('Joined as :role', ['role' => $value('role')]),
            self::MemberRemoved => ($context['left'] ?? false) === true
                ? __('Left the account')
                : __('Removed :member (:role)', ['member' => $value('member'), 'role' => $value('role')]),
            self::MemberRoleChanged => __('Changed :member from :from to :to', ['member' => $value('member'), 'from' => $value('from'), 'to' => $value('to')]),
            self::ProfileUpdated => __('Updated their profile'),
            self::PasswordChanged => __('Changed their password'),
            self::TwoFactorEnabled => __('Turned on two-factor authentication'),
            self::TwoFactorDisabled => __('Turned off two-factor authentication'),
            self::RecoveryCodesRegenerated => __('Created new recovery codes'),
            self::PasskeyAdded => __('Added the passkey “:name”', ['name' => $value('name')]),
            self::PasskeyRemoved => __('Removed the passkey “:name”', ['name' => $value('name')]),
            self::SocialConnected => __('Connected :provider', ['provider' => $value('provider')]),
            self::SocialDisconnected => __('Disconnected :provider', ['provider' => $value('provider')]),
            self::BrowsersSignedOut => trans_choice('Signed out :count other browser|Signed out :count other browsers', (int) $value('count'), ['count' => $value('count')]),
        };
    }
}
