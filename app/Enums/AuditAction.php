<?php

declare(strict_types=1);

namespace App\Enums;

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
    case DomainAdded = 'domain.added';
    case DomainVerified = 'domain.verified';
    case DomainRemoved = 'domain.removed';
    case ServiceEnabled = 'service.enabled';
    case ServiceDisabled = 'service.disabled';
    case MemberServiceAccessChanged = 'member.service_access';
    case PlanChanged = 'billing.plan_changed';
    case ApiTokenCreated = 'api_token.created';
    case ApiTokenRevoked = 'api_token.revoked';
    case MonitorCreated = 'monitor.created';
    case MonitorUpdated = 'monitor.updated';
    case MonitorArchived = 'monitor.archived';
    case AlertDestinationCreated = 'alert_destination.created';
    case AlertDestinationUpdated = 'alert_destination.updated';
    case AlertDestinationRotated = 'alert_destination.rotated';
    case AlertDestinationArchived = 'alert_destination.archived';
    case AlertRuleCreated = 'alert_rule.created';
    case AlertRuleUpdated = 'alert_rule.updated';
    case AlertRuleArchived = 'alert_rule.archived';
    case AlertRoutingUpdated = 'alert_routing.updated';
    case AlertEscalationsUpdated = 'alert_escalations.updated';
    case IngestTokenCreated = 'ingest_token.created';
    case IngestTokenRotated = 'ingest_token.rotated';
    case IngestTokenRevoked = 'ingest_token.revoked';
    case ProviderCreated = 'provider.created';
    case ProviderUpdated = 'provider.updated';
    case ProviderDeleted = 'provider.deleted';
    case ServerCreated = 'server.created';
    case ServerImported = 'server.imported';
    case ServerRenamed = 'server.renamed';
    case ServerDeleted = 'server.deleted';
    case WebsiteCreated = 'website.created';
    case WebsiteImported = 'website.imported';
    case WebsiteUpdated = 'website.updated';
    case WebsiteDeleted = 'website.deleted';
    case WebsiteDomainAdded = 'website_domain.added';
    case WebsiteDomainRemoved = 'website_domain.removed';
    case BackupDestinationCreated = 'backup_destination.created';
    case BackupDestinationUpdated = 'backup_destination.updated';
    case BackupDestinationDeleted = 'backup_destination.deleted';
    case WebsiteBackupRestored = 'website_backup.restored';
    case ServerTerminalOpened = 'server_terminal.opened';
    case ServerTerminalClosed = 'server_terminal.closed';
    case DashboardCreated = 'dashboard.created';
    case DashboardUpdated = 'dashboard.updated';
    case DashboardDeleted = 'dashboard.deleted';
    case StatusPageCreated = 'status_page.created';
    case StatusPageUpdated = 'status_page.updated';
    case StatusPageDeleted = 'status_page.deleted';
    case StatusUpdatePosted = 'status_update.posted';
    case StatusUpdateChanged = 'status_update.changed';

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
            self::DomainAdded => __('Added :domain to :project', ['domain' => $value('domain'), 'project' => $value('project')]),
            self::DomainVerified => __('Verified :domain for :project', ['domain' => $value('domain'), 'project' => $value('project')]),
            self::DomainRemoved => __('Removed :domain from :project', ['domain' => $value('domain'), 'project' => $value('project')]),
            self::ServiceEnabled => __('Turned on :service for :project', ['service' => $value('service'), 'project' => $value('project')]),
            self::ServiceDisabled => __('Turned off :service for :project', ['service' => $value('service'), 'project' => $value('project')]),
            self::MemberServiceAccessChanged => __('Set :member’s services to :services', ['member' => $value('member'), 'services' => $value('services')]),
            self::PlanChanged => ($context['effective_at'] ?? null) !== null
                ? __('Scheduled :service to move from :from to :to on :date', ['service' => $value('service'), 'from' => $value('from'), 'to' => $value('to'), 'date' => $value('effective_at')])
                : __('Changed :service from :from to :to', ['service' => $value('service'), 'from' => $value('from'), 'to' => $value('to')]),
            self::ApiTokenCreated => __('Created the API token “:name” (:scopes)', ['name' => $value('name'), 'scopes' => $value('scopes')]),
            self::ApiTokenRevoked => __('Revoked the API token “:name”', ['name' => $value('name')]),
            self::MonitorCreated => __('Added the monitor “:monitor” to :project', ['monitor' => $value('monitor'), 'project' => $value('project')]),
            self::MonitorUpdated => __('Changed the monitor “:monitor” in :project', ['monitor' => $value('monitor'), 'project' => $value('project')]),
            self::MonitorArchived => __('Archived the monitor “:monitor” in :project', ['monitor' => $value('monitor'), 'project' => $value('project')]),
            self::AlertDestinationCreated => __('Added the alert destination “:destination”', ['destination' => $value('destination')]),
            self::AlertDestinationUpdated => __('Changed the alert destination “:destination”', ['destination' => $value('destination')]),
            self::AlertDestinationRotated => __('Replaced the signing key of “:destination”', ['destination' => $value('destination')]),
            self::AlertDestinationArchived => __('Archived the alert destination “:destination”', ['destination' => $value('destination')]),
            self::AlertRuleCreated => __('Added the alert rule “:rule” to :project', ['rule' => $value('rule'), 'project' => $value('project')]),
            self::AlertRuleUpdated => __('Changed the alert rule “:rule” in :project', ['rule' => $value('rule'), 'project' => $value('project')]),
            self::AlertRuleArchived => __('Archived the alert rule “:rule” in :project', ['rule' => $value('rule'), 'project' => $value('project')]),
            self::AlertRoutingUpdated => __('Changed where “:rule” alerts go', ['rule' => $value('rule')]),
            self::AlertEscalationsUpdated => __('Changed the escalation steps of “:rule”', ['rule' => $value('rule')]),
            self::IngestTokenCreated => __('Created the ingest key “:name” for :project (:environment)', ['name' => $value('name'), 'project' => $value('project'), 'environment' => $value('environment')]),
            self::IngestTokenRotated => __('Replaced the ingest key “:name” for :project (:environment)', ['name' => $value('name'), 'project' => $value('project'), 'environment' => $value('environment')]),
            self::IngestTokenRevoked => __('Revoked the ingest key “:name” for :project (:environment)', ['name' => $value('name'), 'project' => $value('project'), 'environment' => $value('environment')]),
            self::ProviderCreated => __('Connected :type as “:provider”', ['type' => $value('type'), 'provider' => $value('provider')]),
            self::ProviderUpdated => __('Changed the provider “:provider”', ['provider' => $value('provider')]),
            self::ProviderDeleted => __('Removed the provider “:provider”', ['provider' => $value('provider')]),
            self::ServerCreated => __('Created the server :server on :provider', ['server' => $value('server'), 'provider' => $value('provider')]),
            self::ServerImported => __('Imported the server :server (:ip)', ['server' => $value('server'), 'ip' => $value('ip')]),
            self::ServerRenamed => __('Renamed the server :server to :name', ['server' => $value('server'), 'name' => $value('name')]),
            self::ServerDeleted => __('Deleted the server :server', ['server' => $value('server')]),
            self::WebsiteCreated => __('Created the website :website on :server', ['website' => $value('website'), 'server' => $value('server')]),
            self::WebsiteImported => __('Imported the website :website on :server', ['website' => $value('website'), 'server' => $value('server')]),
            self::WebsiteUpdated => __('Changed the website :website', ['website' => $value('website')]),
            self::WebsiteDeleted => __('Deleted the website :website', ['website' => $value('website')]),
            self::WebsiteDomainAdded => __('Added :domain to the website :website', ['domain' => $value('domain'), 'website' => $value('website')]),
            self::WebsiteDomainRemoved => __('Removed :domain from the website :website', ['domain' => $value('domain'), 'website' => $value('website')]),
            self::BackupDestinationCreated => __('Added the backup destination “:destination”', ['destination' => $value('destination')]),
            self::BackupDestinationUpdated => __('Changed the backup destination “:destination”', ['destination' => $value('destination')]),
            self::BackupDestinationDeleted => __('Removed the backup destination “:destination”', ['destination' => $value('destination')]),
            self::ServerTerminalOpened => __('Opened a terminal on the server :server', ['server' => $value('server')]),
            self::ServerTerminalClosed => __('Closed a terminal on the server :server', ['server' => $value('server')]),
            self::WebsiteBackupRestored => __('Restored the website :website from the backup of :date', ['website' => $value('website'), 'date' => $value('date')]),
            self::DashboardCreated => __('Created the dashboard “:dashboard”', ['dashboard' => $value('dashboard')]),
            self::DashboardUpdated => __('Changed the dashboard “:dashboard”', ['dashboard' => $value('dashboard')]),
            self::DashboardDeleted => __('Deleted the dashboard “:dashboard”', ['dashboard' => $value('dashboard')]),
            self::StatusPageCreated => __('Created the status page “:page”', ['page' => $value('page')]),
            self::StatusPageUpdated => __('Changed the status page “:page”', ['page' => $value('page')]),
            self::StatusPageDeleted => __('Deleted the status page “:page”', ['page' => $value('page')]),
            self::StatusUpdatePosted => __('Posted “:title” on the status page “:page”', ['title' => $value('title'), 'page' => $value('page')]),
            self::StatusUpdateChanged => __('Updated “:title” on the status page “:page” (:status)', ['title' => $value('title'), 'page' => $value('page'), 'status' => $value('status')]),
            self::BrowsersSignedOut => trans_choice('Signed out :count other browser|Signed out :count other browsers', (int) $value('count'), ['count' => $value('count')]),
        };
    }
}
