<?php

declare(strict_types=1);

namespace App\Enums;

enum AuditAction: string
{
    case AccountCreated = 'account.created';
    case AccountRenamed = 'account.renamed';
    case SecurityRulesChanged = 'security_rules.changed';
    case SsoSignedIn = 'sso.signed_in';
    case MemberInvited = 'member.invited';
    case InvitationRevoked = 'invitation.revoked';
    case InvitationAccepted = 'invitation.accepted';
    case MemberRemoved = 'member.removed';
    case MemberRoleChanged = 'member.role_changed';
    case MemberProvisioned = 'member.provisioned';
    case ScimChanged = 'sso.scim_changed';
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
    case PayAsYouGoChanged = 'billing.pay_as_you_go';
    case SecurityFindingIgnored = 'security_finding.ignored';
    case SecurityFindingReopened = 'security_finding.reopened';
    case SecurityFixApplied = 'security_fix.applied';
    case SecurityZoneChanged = 'security_zone.changed';
    case SshAccessGranted = 'ssh_access.granted';
    case SshAccessRevoked = 'ssh_access.revoked';
    case AccessReviewCompleted = 'access_review.completed';
    case EvidenceExported = 'security_evidence.exported';
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
    case ServerTaskSaved = 'server_task.saved';
    case MaintenanceStarted = 'maintenance.started';
    case MaintenanceEnded = 'maintenance.ended';
    case MigrationsApproved = 'migrations.approved';
    case ServerTaskRemoved = 'server_task.removed';
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
    case AnalyticsReportShared = 'analytics_share.enabled';
    case AnalyticsReportUnshared = 'analytics_share.disabled';

    /**
     * The groups the audit log can be filtered by, with their labels.
     *
     * @var array<string, string>
     */
    public const CATEGORIES = ['team' => 'Account and team', 'security' => 'Sign-in and security', 'billing' => 'Billing', 'infrastructure' => 'Infrastructure', 'deploy' => 'Deploy', 'monitoring' => 'Monitoring'];

    /**
     * Get the group this action belongs to, for filtering the audit log.
     *
     * @return string one of CATEGORIES' keys
     */
    public function category(): string
    {
        return match (explode('.', $this->value)[0]) {
            'two_factor', 'passkey', 'password', 'profile', 'sessions', 'social', 'api_token', 'security_rules', 'sso', 'security_finding', 'security_fix', 'security_zone', 'ssh_access', 'access_review', 'security_evidence' => 'security',
            'billing' => 'billing',
            'server', 'server_task', 'server_terminal', 'website', 'website_domain', 'website_backup', 'domain', 'provider', 'backup_destination' => 'infrastructure',
            'environment', 'maintenance', 'migrations' => 'deploy',
            'monitor', 'alert_destination', 'alert_escalations', 'alert_routing', 'alert_rule', 'dashboard', 'ingest_token', 'status_page', 'status_update' => 'monitoring',
            default => 'team',
        };
    }

    /**
     * List the actions in one group.
     *
     * @param  string  $category
     * @return list<self>
     */
    public static function inCategory(string $category): array
    {
        return array_values(array_filter(self::cases(), fn (self $action): bool => $action->category() === $category));
    }

    /**
     * Describe the entry in a sentence for the audit log, filled in from the context recorded with it. Missing values
     * print as "?" so an old entry with less context still reads.
     *
     * @param  array<string, mixed>  $context
     * @return string
     */
    public function describe(array $context): string
    {
        $value = fn (string $key): string => is_scalar($context[$key] ?? null) ? (string) $context[$key] : '?';

        return match ($this) {
            self::AccountCreated => __('Created the account :name', ['name' => $value('name')]),
            self::AccountRenamed => __('Renamed the account from :from to :to', ['from' => $value('from'), 'to' => $value('to')]),
            self::SecurityRulesChanged => __('Changed the account’s security rules: :changes', ['changes' => $value('changes')]),
            self::SsoSignedIn => __('Signed in with single sign-on'),
            self::MemberInvited => __('Invited :email as :role', ['email' => $value('email'), 'role' => $value('role')]),
            self::InvitationRevoked => __('Revoked the invitation for :email', ['email' => $value('email')]),
            self::InvitationAccepted => __('Joined as :role', ['role' => $value('role')]),
            self::MemberRemoved => ($context['left'] ?? false) === true
                ? __('Left the account')
                : __('Removed :member (:role)', ['member' => $value('member'), 'role' => $value('role')]),
            self::MemberProvisioned => __('Added :member as :role through SCIM', ['member' => $value('member'), 'role' => $value('role')]),
            self::ScimChanged => __('Changed SCIM provisioning: :change', ['change' => $value('change')]),
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
            self::SecurityFindingIgnored => __('Ignored the security finding “:finding”', ['finding' => $value('finding')]),
            self::SshAccessGranted => __('Gave :member SSH access to the server :server', ['member' => $value('member'), 'server' => $value('server')]),
            self::SshAccessRevoked => __('Removed :member’s SSH access to the server :server', ['member' => $value('member'), 'server' => $value('server')]),
            self::EvidenceExported => __('Downloaded the security evidence pack (:months months)', ['months' => $value('months')]),
            self::AccessReviewCompleted => __('Completed an access review (:removed removed)', ['removed' => $value('removed')]),
            self::SecurityZoneChanged => ($context['under_attack'] ?? false)
                ? __('Turned on under attack mode for :zone', ['zone' => $value('zone')])
                : __('Changed Cloudflare security settings for :zone', ['zone' => $value('zone')]),
            self::SecurityFixApplied => __('Applied “:fix” on the server :server', ['fix' => $value('fix'), 'server' => $value('server')]),
            self::SecurityFindingReopened => __('Reopened the security finding “:finding”', ['finding' => $value('finding')]),
            self::PayAsYouGoChanged => ($context['enabled'] ?? false)
                ? __('Turned on pay as you go for :meter', ['meter' => $value('meter')])
                : __('Turned off pay as you go for :meter', ['meter' => $value('meter')]),
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
            self::ServerTaskSaved => __('Set up :task on the server :server', ['task' => $value('task'), 'server' => $value('server')]),
            self::MaintenanceStarted => __('Put :environment into maintenance mode', ['environment' => $value('environment')]),
            self::MaintenanceEnded => __('Brought :environment out of maintenance mode', ['environment' => $value('environment')]),
            self::MigrationsApproved => __('Approved destructive migrations in deploy #:build (:revision)', ['build' => $value('build'), 'revision' => $value('revision')]),
            self::ServerTaskRemoved => __('Removed :task from the server :server', ['task' => $value('task'), 'server' => $value('server')]),
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
            self::AnalyticsReportShared => $value('protected') === '1' ? __('Shared the Analytics report for “:site” behind a password', ['site' => $value('site')]) : __('Shared the Analytics report for “:site” by link', ['site' => $value('site')]),
            self::AnalyticsReportUnshared => __('Stopped sharing the Analytics report for “:site”', ['site' => $value('site')]),
            self::BrowsersSignedOut => trans_choice('Signed out :count other browser|Signed out :count other browsers', (int) $value('count'), ['count' => $value('count')]),
        };
    }
}
