<?php

declare(strict_types=1);

namespace App\Domain\Audit\Listeners;

use App\Domain\Accounts\Events\AccountCreated;
use App\Domain\Accounts\Events\AccountRenamed;
use App\Domain\Accounts\Events\InvitationAccepted;
use App\Domain\Accounts\Events\InvitationRevoked;
use App\Domain\Accounts\Events\MemberInvited;
use App\Domain\Accounts\Events\MemberRemoved;
use App\Domain\Accounts\Events\MemberRoleChanged;
use App\Domain\Accounts\Events\MemberServiceAccessChanged;
use App\Domain\Api\Events\ApiTokenCreated;
use App\Domain\Api\Events\ApiTokenRevoked;
use App\Domain\Audit\Actions\RecordAuditEntry;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Identity\Events\BrowsersSignedOut;
use App\Domain\Identity\Events\PasswordChanged;
use App\Domain\Identity\Events\ProfileUpdated;
use App\Domain\Identity\Events\SocialIdentityConnected;
use App\Domain\Identity\Events\SocialIdentityDisconnected;
use App\Domain\Identity\Events\UserDeleting;
use App\Domain\Identity\Models\User;
use App\Domain\Projects\Events\DomainAdded;
use App\Domain\Projects\Events\DomainRemoved;
use App\Domain\Projects\Events\DomainVerified;
use App\Domain\Projects\Events\EnvironmentCreated;
use App\Domain\Projects\Events\EnvironmentDeleted;
use App\Domain\Projects\Events\ProjectCreated;
use App\Domain\Projects\Events\ProjectDeleted;
use App\Domain\Projects\Events\ProjectUpdated;
use App\Domain\Projects\Events\ServiceDisabled;
use App\Domain\Projects\Events\ServiceEnabled;
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
    public function __construct(private readonly RecordAuditEntry $record, private readonly ServiceRegistry $services) {}

    /** @return array<class-string, string> */
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
        ];
    }

    public function accountCreated(AccountCreated $event): void
    {
        $this->record->handle(AuditAction::AccountCreated, $event->owner, $event->account->id, ['name' => $event->account->name]);
    }

    public function accountRenamed(AccountRenamed $event): void
    {
        $this->record->handle(AuditAction::AccountRenamed, $event->actor, $event->account->id, ['from' => $event->from, 'to' => $event->account->name]);
    }

    public function memberInvited(MemberInvited $event): void
    {
        $this->record->handle(AuditAction::MemberInvited, $event->actor, $event->invitation->account_id, [
            'email' => $event->invitation->email,
            'role' => $event->invitation->role->label(),
        ]);
    }

    public function invitationRevoked(InvitationRevoked $event): void
    {
        $this->record->handle(AuditAction::InvitationRevoked, $event->actor, $event->invitation->account_id, ['email' => $event->invitation->email]);
    }

    public function invitationAccepted(InvitationAccepted $event): void
    {
        $this->record->handle(AuditAction::InvitationAccepted, $event->membership->user, $event->membership->account_id, ['role' => $event->membership->role->label()]);
    }

    public function memberRemoved(MemberRemoved $event): void
    {
        $this->record->handle(AuditAction::MemberRemoved, $event->actor, $event->account->id, [
            'member' => $this->person($event->member),
            'role' => $event->role->label(),
            'left' => $event->member->is($event->actor),
        ]);
    }

    public function memberRoleChanged(MemberRoleChanged $event): void
    {
        $this->record->handle(AuditAction::MemberRoleChanged, $event->actor, $event->membership->account_id, [
            'member' => $this->person($event->membership->user),
            'from' => $event->from->label(),
            'to' => $event->to->label(),
        ]);
    }

    public function memberServiceAccessChanged(MemberServiceAccessChanged $event): void
    {
        $services = $event->membership->service_access;
        $this->record->handle(AuditAction::MemberServiceAccessChanged, $event->actor, $event->membership->account_id, [
            'member' => $this->person($event->membership->user),
            'services' => $services === null ? __('all services') : ($services === [] ? __('none') : implode(', ', array_map($this->serviceName(...), $services))),
        ]);
    }

    public function projectCreated(ProjectCreated $event): void
    {
        $this->record->handle(AuditAction::ProjectCreated, $event->actor, $event->project->account_id, ['project' => $event->project->name]);
    }

    public function projectUpdated(ProjectUpdated $event): void
    {
        $this->record->handle(AuditAction::ProjectUpdated, $event->actor, $event->project->account_id, ['project' => $event->project->name, 'previous_name' => $event->previousName]);
    }

    public function projectDeleted(ProjectDeleted $event): void
    {
        $this->record->handle(AuditAction::ProjectDeleted, $event->actor, $event->accountId, ['project' => $event->name]);
    }

    public function environmentCreated(EnvironmentCreated $event): void
    {
        $project = $event->environment->project;
        $this->record->handle(AuditAction::EnvironmentCreated, $event->actor, $project->account_id, ['project' => $project->name, 'environment' => $event->environment->name]);
    }

    public function environmentDeleted(EnvironmentDeleted $event): void
    {
        $this->record->handle(AuditAction::EnvironmentDeleted, $event->actor, $event->project->account_id, ['project' => $event->project->name, 'environment' => $event->name]);
    }

    public function domainChanged(DomainAdded|DomainVerified|DomainRemoved $event): void
    {
        $action = match (true) {
            $event instanceof DomainAdded => AuditAction::DomainAdded,
            $event instanceof DomainVerified => AuditAction::DomainVerified,
            default => AuditAction::DomainRemoved,
        };
        $project = $event->domain->project;
        $this->record->handle($action, $event->actor, $project->account_id, ['project' => $project->name, 'domain' => $event->domain->displayName()]);
    }

    public function serviceEnabled(ServiceEnabled $event): void
    {
        $this->record->handle(AuditAction::ServiceEnabled, $event->actor, $event->project->account_id, ['project' => $event->project->name, 'service' => $this->serviceName($event->service)]);
    }

    public function serviceDisabled(ServiceDisabled $event): void
    {
        $this->record->handle(AuditAction::ServiceDisabled, $event->actor, $event->project->account_id, ['project' => $event->project->name, 'service' => $this->serviceName($event->service)]);
    }

    public function profileUpdated(ProfileUpdated $event): void
    {
        $this->record->handle(AuditAction::ProfileUpdated, $event->user);
    }

    public function passwordChanged(PasswordChanged $event): void
    {
        $this->record->handle(AuditAction::PasswordChanged, $event->user);
    }

    public function twoFactorEnabled(TwoFactorAuthenticationConfirmed $event): void
    {
        $this->personal(AuditAction::TwoFactorEnabled, $event->user);
    }

    public function twoFactorDisabled(TwoFactorAuthenticationDisabled $event): void
    {
        $this->personal(AuditAction::TwoFactorDisabled, $event->user);
    }

    public function recoveryCodesGenerated(RecoveryCodesGenerated $event): void
    {
        // Fortify also fires this while setting up two-factor; only a deliberate regeneration is worth logging.
        $this->personal(AuditAction::RecoveryCodesRegenerated, $event->user, when: fn (User $user): bool => $user->hasEnabledTwoFactorAuthentication());
    }

    public function passkeyAdded(PasskeyRegistered $event): void
    {
        $this->personal(AuditAction::PasskeyAdded, $event->user, ['name' => (string) $event->passkey->name]);
    }

    public function passkeyRemoved(PasskeyDeleted $event): void
    {
        $this->personal(AuditAction::PasskeyRemoved, $event->user, ['name' => (string) $event->passkey->name]);
    }

    public function socialConnected(SocialIdentityConnected $event): void
    {
        $this->record->handle(AuditAction::SocialConnected, $event->user, context: ['provider' => $event->provider->label()]);
    }

    public function socialDisconnected(SocialIdentityDisconnected $event): void
    {
        $this->record->handle(AuditAction::SocialDisconnected, $event->user, context: ['provider' => $event->provider->label()]);
    }

    public function browsersSignedOut(BrowsersSignedOut $event): void
    {
        $this->record->handle(AuditAction::BrowsersSignedOut, $event->user, context: ['count' => $event->count]);
    }

    public function apiTokenCreated(ApiTokenCreated $event): void
    {
        $this->record->handle(AuditAction::ApiTokenCreated, $event->actor, $event->token->account_id, [
            'name' => $event->token->name,
            'scopes' => implode(', ', $event->token->abilities),
        ]);
    }

    public function apiTokenRevoked(ApiTokenRevoked $event): void
    {
        $this->record->handle(AuditAction::ApiTokenRevoked, $event->actor, $event->token->account_id, ['name' => $event->token->name]);
    }

    /** A deleted user's own security log goes with them; shared accounts keep their record of what the person did. */
    public function forgetPersonalEntries(UserDeleting $event): void
    {
        AuditEntry::query()->whereNull('account_id')->where('actor_id', $event->user->id)->delete();
    }

    /**
     * Fortify and Passkeys events type their user loosely, so check it is ours before recording.
     *
     * @param  array<string, scalar|null>  $context
     * @param  (callable(User): bool)|null  $when
     */
    private function personal(AuditAction $action, mixed $user, array $context = [], ?callable $when = null): void
    {
        if ($user instanceof User && ($when === null || $when($user))) {
            $this->record->handle($action, $user, context: $context);
        }
    }

    private function serviceName(string $key): string
    {
        return $this->services->find($key)?->name() ?? $key;
    }

    private function person(User $user): string
    {
        return "{$user->name} <{$user->email}>";
    }
}
