<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Actions\Audit\RecordAuditEntry;
use App\Enums\AuditAction;
use App\Events\Accounts\AccountCreated;
use App\Events\Accounts\AccountRenamed;
use App\Events\Accounts\InvitationAccepted;
use App\Events\Accounts\InvitationRevoked;
use App\Events\Accounts\MemberInvited;
use App\Events\Accounts\MemberRemoved;
use App\Events\Accounts\MemberRoleChanged;
use App\Events\Accounts\MemberServiceAccessChanged;
use App\Events\ApiTokens\ApiTokenCreated;
use App\Events\ApiTokens\ApiTokenRevoked;
use App\Events\Billing\ServiceTierChanged;
use App\Events\Projects\DomainAdded;
use App\Events\Projects\DomainRemoved;
use App\Events\Projects\DomainVerified;
use App\Events\Projects\EnvironmentCreated;
use App\Events\Projects\EnvironmentDeleted;
use App\Events\Projects\ProjectCreated;
use App\Events\Projects\ProjectDeleted;
use App\Events\Projects\ProjectUpdated;
use App\Events\Projects\ServiceDisabled;
use App\Events\Projects\ServiceEnabled;
use App\Events\Users\BrowsersSignedOut;
use App\Events\Users\PasswordChanged;
use App\Events\Users\ProfileUpdated;
use App\Events\Users\SocialIdentityConnected;
use App\Events\Users\SocialIdentityDisconnected;
use App\Events\Users\UserDeleting;
use App\Models\AuditEntry;
use App\Models\User;
use App\Platform\ServiceRegistry;
use Illuminate\Events\Dispatcher;
use Laravel\Fortify\Events\RecoveryCodesGenerated;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Events\TwoFactorAuthenticationDisabled;
use Laravel\Passkeys\Events\PasskeyDeleted;
use Laravel\Passkeys\Events\PasskeyRegistered;

/** Writes the audit log from the other contexts' domain events; those contexts don't know it exists. */
final class AuditSubscriber
{
    /**
     * Create a new AuditSubscriber instance.
     *
     * Turns domain, Fortify and Passkeys events into audit entries.
     *
     * @param  RecordAuditEntry  $record  Writes the entries.
     * @param  ServiceRegistry  $services  Turns service keys into the names people read in the log.
     */
    public function __construct(private readonly RecordAuditEntry $record, private readonly ServiceRegistry $services) {}

    /**
     * Register every event that leaves an audit entry, and the method that records it.
     *
     * @param  Dispatcher  $events
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            AccountCreated::class => 'accountCreated',
            AccountRenamed::class => 'accountRenamed',
            MemberInvited::class => 'memberInvited',
            InvitationRevoked::class => 'invitationRevoked',
            InvitationAccepted::class => 'invitationAccepted',
            MemberRemoved::class => 'memberRemoved',
            MemberRoleChanged::class => 'memberRoleChanged',
            MemberServiceAccessChanged::class => 'memberServiceAccessChanged',
            ProjectCreated::class => 'projectCreated',
            ProjectUpdated::class => 'projectUpdated',
            ProjectDeleted::class => 'projectDeleted',
            EnvironmentCreated::class => 'environmentCreated',
            EnvironmentDeleted::class => 'environmentDeleted',
            DomainAdded::class => 'domainChanged',
            DomainVerified::class => 'domainChanged',
            DomainRemoved::class => 'domainChanged',
            ServiceEnabled::class => 'serviceEnabled',
            ServiceDisabled::class => 'serviceDisabled',
            ProfileUpdated::class => 'profileUpdated',
            PasswordChanged::class => 'passwordChanged',
            TwoFactorAuthenticationConfirmed::class => 'twoFactorEnabled',
            TwoFactorAuthenticationDisabled::class => 'twoFactorDisabled',
            RecoveryCodesGenerated::class => 'recoveryCodesGenerated',
            PasskeyRegistered::class => 'passkeyAdded',
            PasskeyDeleted::class => 'passkeyRemoved',
            SocialIdentityConnected::class => 'socialConnected',
            SocialIdentityDisconnected::class => 'socialDisconnected',
            BrowsersSignedOut::class => 'browsersSignedOut',
            ApiTokenCreated::class => 'apiTokenCreated',
            ApiTokenRevoked::class => 'apiTokenRevoked',
            UserDeleting::class => 'forgetPersonalEntries',
            ServiceTierChanged::class => 'planChanged',
        ];
    }

    /**
     * Record the account's creation, attributed to its first owner.
     *
     * @param  AccountCreated  $event
     * @return void
     */
    public function accountCreated(AccountCreated $event): void
    {
        $this->record->handle(AuditAction::AccountCreated, $event->owner, $event->account->id, ['name' => $event->account->name]);
    }

    /**
     * Record a rename with the old and new names.
     *
     * @param  AccountRenamed  $event
     * @return void
     */
    public function accountRenamed(AccountRenamed $event): void
    {
        $this->record->handle(AuditAction::AccountRenamed, $event->actor, $event->account->id, ['from' => $event->from, 'to' => $event->account->name]);
    }

    /**
     * Record who was invited and with which role.
     *
     * @param  MemberInvited  $event
     * @return void
     */
    public function memberInvited(MemberInvited $event): void
    {
        $this->record->handle(AuditAction::MemberInvited, $event->actor, $event->invitation->account_id, [
            'email' => $event->invitation->email,
            'role' => $event->invitation->role->label(),
        ]);
    }

    /**
     * Record which invitation was withdrawn.
     *
     * @param  InvitationRevoked  $event
     * @return void
     */
    public function invitationRevoked(InvitationRevoked $event): void
    {
        $this->record->handle(AuditAction::InvitationRevoked, $event->actor, $event->invitation->account_id, ['email' => $event->invitation->email]);
    }

    /**
     * Record the new member joining, attributed to them.
     *
     * @param  InvitationAccepted  $event
     * @return void
     */
    public function invitationAccepted(InvitationAccepted $event): void
    {
        $this->record->handle(AuditAction::InvitationAccepted, $event->membership->user, $event->membership->account_id, ['role' => $event->membership->role->label()]);
    }

    /**
     * Record a removal, or the member leaving when they removed themselves.
     *
     * @param  MemberRemoved  $event
     * @return void
     */
    public function memberRemoved(MemberRemoved $event): void
    {
        $this->record->handle(AuditAction::MemberRemoved, $event->actor, $event->account->id, [
            'member' => $this->person($event->member),
            'role' => $event->role->label(),
            'left' => $event->member->is($event->actor),
        ]);
    }

    /**
     * Record a role change with the old and new roles.
     *
     * @param  MemberRoleChanged  $event
     * @return void
     */
    public function memberRoleChanged(MemberRoleChanged $event): void
    {
        $this->record->handle(AuditAction::MemberRoleChanged, $event->actor, $event->membership->account_id, [
            'member' => $this->person($event->membership->user),
            'from' => $event->from->label(),
            'to' => $event->to->label(),
        ]);
    }

    /**
     * Record the member's new service list: "all services", "none", or the names.
     *
     * @param  MemberServiceAccessChanged  $event
     * @return void
     */
    public function memberServiceAccessChanged(MemberServiceAccessChanged $event): void
    {
        $services = $event->membership->service_access;
        $this->record->handle(AuditAction::MemberServiceAccessChanged, $event->actor, $event->membership->account_id, [
            'member' => $this->person($event->membership->user),
            'services' => $services === null ? __('all services') : ($services === [] ? __('none') : implode(', ', array_map($this->serviceName(...), $services))),
        ]);
    }

    /**
     * Record a new project in the account log and the project's activity.
     *
     * @param  ProjectCreated  $event
     * @return void
     */
    public function projectCreated(ProjectCreated $event): void
    {
        $this->record->handle(AuditAction::ProjectCreated, $event->actor, $event->project->account_id, ['project' => $event->project->name], $event->project->id);
    }

    /**
     * Record a change to a project's details, keeping the previous name for renames.
     *
     * @param  ProjectUpdated  $event
     * @return void
     */
    public function projectUpdated(ProjectUpdated $event): void
    {
        $this->record->handle(AuditAction::ProjectUpdated, $event->actor, $event->project->account_id, ['project' => $event->project->name, 'previous_name' => $event->previousName], $event->project->id);
    }

    /**
     * Record a deleted project in the account log (its own activity went with it).
     *
     * @param  ProjectDeleted  $event
     * @return void
     */
    public function projectDeleted(ProjectDeleted $event): void
    {
        $this->record->handle(AuditAction::ProjectDeleted, $event->actor, $event->accountId, ['project' => $event->name]);
    }

    /**
     * Record a new environment in the project's activity.
     *
     * @param  EnvironmentCreated  $event
     * @return void
     */
    public function environmentCreated(EnvironmentCreated $event): void
    {
        $project = $event->environment->project;
        $this->record->handle(AuditAction::EnvironmentCreated, $event->actor, $project->account_id, ['project' => $project->name, 'environment' => $event->environment->name], $project->id);
    }

    /**
     * Record a deleted environment in the project's activity.
     *
     * @param  EnvironmentDeleted  $event
     * @return void
     */
    public function environmentDeleted(EnvironmentDeleted $event): void
    {
        $this->record->handle(AuditAction::EnvironmentDeleted, $event->actor, $event->project->account_id, ['project' => $event->project->name, 'environment' => $event->name], $event->project->id);
    }

    /**
     * Record a domain being added, verified or removed in the project's activity.
     *
     * @param  DomainAdded|DomainVerified|DomainRemoved  $event
     * @return void
     */
    public function domainChanged(DomainAdded|DomainVerified|DomainRemoved $event): void
    {
        $action = match (true) {
            $event instanceof DomainAdded => AuditAction::DomainAdded,
            $event instanceof DomainVerified => AuditAction::DomainVerified,
            default => AuditAction::DomainRemoved,
        };
        $project = $event->domain->project;
        $this->record->handle($action, $event->actor, $project->account_id, ['project' => $project->name, 'domain' => $event->domain->displayName()], $project->id);
    }

    /**
     * Record a service being turned on in a project.
     *
     * @param  ServiceEnabled  $event
     * @return void
     */
    public function serviceEnabled(ServiceEnabled $event): void
    {
        $this->record->handle(AuditAction::ServiceEnabled, $event->actor, $event->project->account_id, ['project' => $event->project->name, 'service' => $this->serviceName($event->service)], $event->project->id);
    }

    /**
     * Record a service being turned off in a project.
     *
     * @param  ServiceDisabled  $event
     * @return void
     */
    public function serviceDisabled(ServiceDisabled $event): void
    {
        $this->record->handle(AuditAction::ServiceDisabled, $event->actor, $event->project->account_id, ['project' => $event->project->name, 'service' => $this->serviceName($event->service)], $event->project->id);
    }

    /**
     * Record a tier change with the tier names, and when it takes effect for scheduled downgrades.
     *
     * @param  ServiceTierChanged  $event
     * @return void
     */
    public function planChanged(ServiceTierChanged $event): void
    {
        $billing = $this->services->find($event->service)?->billing();
        $this->record->handle(AuditAction::PlanChanged, $event->actor, $event->account->id, [
            'service' => $this->serviceName($event->service),
            'from' => $billing?->tier($event->from)->name ?? $event->from,
            'to' => $billing?->tier($event->to)->name ?? $event->to,
            'effective_at' => $event->effectiveAt?->toFormattedDateString(),
        ]);
    }

    /**
     * Record a profile change in the person's own security log.
     *
     * @param  ProfileUpdated  $event
     * @return void
     */
    public function profileUpdated(ProfileUpdated $event): void
    {
        $this->record->handle(AuditAction::ProfileUpdated, $event->user);
    }

    /**
     * Record a password change in the person's own security log.
     *
     * @param  PasswordChanged  $event
     * @return void
     */
    public function passwordChanged(PasswordChanged $event): void
    {
        $this->record->handle(AuditAction::PasswordChanged, $event->user);
    }

    /**
     * Record two-factor authentication being confirmed.
     *
     * @param  TwoFactorAuthenticationConfirmed  $event
     * @return void
     */
    public function twoFactorEnabled(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->personal(AuditAction::TwoFactorEnabled, $event->user);
    }

    /**
     * Record two-factor authentication being turned off.
     *
     * @param  TwoFactorAuthenticationDisabled  $event
     * @return void
     */
    public function twoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->personal(AuditAction::TwoFactorDisabled, $event->user);
    }

    /**
     * Record recovery codes being regenerated.
     *
     * @param  RecoveryCodesGenerated  $event
     * @return void
     */
    public function recoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        // Fortify also fires this while setting up two-factor; only a deliberate regeneration is worth logging.
        $this->personal(AuditAction::RecoveryCodesRegenerated, $event->user, when: fn (User $user): bool => $user->hasEnabledTwoFactorAuthentication());
    }

    /**
     * Record a passkey being registered, by name.
     *
     * @param  PasskeyRegistered  $event
     * @return void
     */
    public function passkeyAdded(PasskeyRegistered $event): void
    {
        $this->personal(AuditAction::PasskeyAdded, $event->user, ['name' => (string) $event->passkey->name]);
    }

    /**
     * Record a passkey being deleted, by name.
     *
     * @param  PasskeyDeleted  $event
     * @return void
     */
    public function passkeyRemoved(PasskeyDeleted $event): void
    {
        $this->personal(AuditAction::PasskeyRemoved, $event->user, ['name' => (string) $event->passkey->name]);
    }

    /**
     * Record a sign-in provider being connected.
     *
     * @param  SocialIdentityConnected  $event
     * @return void
     */
    public function socialConnected(SocialIdentityConnected $event): void
    {
        $this->record->handle(AuditAction::SocialConnected, $event->user, context: ['provider' => $event->provider->label()]);
    }

    /**
     * Record a sign-in provider being disconnected.
     *
     * @param  SocialIdentityDisconnected  $event
     * @return void
     */
    public function socialDisconnected(SocialIdentityDisconnected $event): void
    {
        $this->record->handle(AuditAction::SocialDisconnected, $event->user, context: ['provider' => $event->provider->label()]);
    }

    /**
     * Record other browsers being signed out, with how many.
     *
     * @param  BrowsersSignedOut  $event
     * @return void
     */
    public function browsersSignedOut(BrowsersSignedOut $event): void
    {
        $this->record->handle(AuditAction::BrowsersSignedOut, $event->user, context: ['count' => $event->count]);
    }

    /**
     * Record a new API token with its scopes (never its secret).
     *
     * @param  ApiTokenCreated  $event
     * @return void
     */
    public function apiTokenCreated(ApiTokenCreated $event): void
    {
        $this->record->handle(AuditAction::ApiTokenCreated, $event->actor, $event->token->account_id, [
            'name' => $event->token->name,
            'scopes' => implode(', ', $event->token->abilities),
        ]);
    }

    /**
     * Record a revoked API token.
     *
     * @param  ApiTokenRevoked  $event
     * @return void
     */
    public function apiTokenRevoked(ApiTokenRevoked $event): void
    {
        $this->record->handle(AuditAction::ApiTokenRevoked, $event->actor, $event->token->account_id, ['name' => $event->token->name]);
    }

    /**
     * Delete a deleted user's own security log; shared accounts keep their record of what the person did.
     *
     * @param  UserDeleting  $event
     * @return void
     */
    public function forgetPersonalEntries(UserDeleting $event): void
    {
        AuditEntry::query()->whereNull('account_id')->where('actor_id', $event->user->id)->delete();
    }

    /**
     * Record a personal security entry from a Fortify or Passkeys event. Those events type their user loosely, so
     * check it is ours before recording.
     *
     * @param  AuditAction  $action
     * @param  mixed  $user
     * @param  array<string, scalar|null>  $context
     * @param  (callable(User): bool)|null  $when
     * @return void
     */
    private function personal(AuditAction $action, mixed $user, array $context = [], ?callable $when = null): void
    {
        if ($user instanceof User && ($when === null || $when($user))) {
            $this->record->handle($action, $user, context: $context);
        }
    }

    /**
     * Get the service's display name, or the key itself for a service that no longer exists.
     *
     * @param  string  $key
     * @return string
     */
    private function serviceName(string $key): string
    {
        return $this->services->find($key)?->name() ?? $key;
    }

    /**
     * Describe a member as "Name <email>", so the entry still identifies them after they leave or rename themselves.
     *
     * @param  User  $user
     * @return string
     */
    private function person(User $user): string
    {
        return "{$user->name} <{$user->email}>";
    }
}
