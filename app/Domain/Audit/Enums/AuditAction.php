<?php

declare(strict_types=1);

namespace App\Domain\Audit\Enums;

enum AuditAction: string
{
    case AccountCreated = 'account.created';
    case AccountRenamed = 'account.renamed';
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
    case ProjectCreated = 'project.created';
    case ProjectUpdated = 'project.updated';
    case ProjectDeleted = 'project.deleted';
    case EnvironmentCreated = 'environment.created';
    case EnvironmentDeleted = 'environment.deleted';
    case ServiceEnabled = 'service.enabled';
    case ServiceDisabled = 'service.disabled';
    case MemberServiceAccessChanged = 'member.service_access';
    case ApiTokenCreated = 'api_token.created';
    case ApiTokenRevoked = 'api_token.revoked';

    /** @param array<string, mixed> $context */
    public function describe(array $context): string
    {
        $value = fn (string $key): string => is_scalar($context[$key] ?? null) ? (string) $context[$key] : '?';

        return match ($this) {
            self::AccountCreated => __('Created the account :name', ['name' => $value('name')]),
            self::AccountRenamed => __('Renamed the account from :from to :to', ['from' => $value('from'), 'to' => $value('to')]),
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
            self::ProjectCreated => __('Created the project :project', ['project' => $value('project')]),
            self::ProjectUpdated => __('Updated the project :project', ['project' => $value('project')]),
            self::ProjectDeleted => __('Deleted the project :project', ['project' => $value('project')]),
            self::EnvironmentCreated => __('Added the :environment environment to :project', ['environment' => $value('environment'), 'project' => $value('project')]),
            self::EnvironmentDeleted => __('Removed the :environment environment from :project', ['environment' => $value('environment'), 'project' => $value('project')]),
            self::ServiceEnabled => __('Turned on :service for :project', ['service' => $value('service'), 'project' => $value('project')]),
            self::ServiceDisabled => __('Turned off :service for :project', ['service' => $value('service'), 'project' => $value('project')]),
            self::MemberServiceAccessChanged => __('Set :member’s services to :services', ['member' => $value('member'), 'services' => $value('services')]),
            self::ApiTokenCreated => __('Created the API token “:name” (:scopes)', ['name' => $value('name'), 'scopes' => $value('scopes')]),
            self::ApiTokenRevoked => __('Revoked the API token “:name”', ['name' => $value('name')]),
            self::BrowsersSignedOut => trans_choice('Signed out :count other browser|Signed out :count other browsers', (int) $value('count'), ['count' => $value('count')]),
        };
    }
}
