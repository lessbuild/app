<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Data\Accounts\InviteMemberData;
use App\Events\Accounts\MemberInvited;
use App\Exceptions\AccountRuleViolation;
use App\Models\Account;
use App\Models\AccountInvitation;
use App\Models\User;
use App\Notifications\AccountInvitationNotification;
use App\Services\Billing\Entitlements;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

final class InviteMember
{
    /**
     * Invites someone to the account, within the plan's member limit.
     *
     * @param  Entitlements  $entitlements  Checks the account's member limit.
     */
    public function __construct(private readonly Entitlements $entitlements) {}

    public const EXPIRES_AFTER_DAYS = 7;

    /** Invite an email address; re-inviting the same address replaces its previous pending invitation. */
    public function handle(User $actor, Account $account, InviteMemberData $data): AccountInvitation
    {
        Gate::forUser($actor)->authorize('manageMembers', $account);
        if (! ($account->roleOf($actor)?->canAssign($data->role) ?? false)) {
            throw AccountRuleViolation::cannotAssign();
        }
        $email = Str::lower(trim($data->email));
        if ($account->members()->whereRaw('lower(email) = ?', [$email])->exists()) {
            throw AccountRuleViolation::alreadyMember();
        }
        // Pending invitations hold a seat; re-inviting the same address replaces its invitation.
        $seats = $account->memberships()->count() + $account->invitations()->pending()->where('email', '!=', $email)->count() + 1;
        $decision = $this->entitlements->for($account)->allows('account.members.max', $seats);
        if (! $decision->allowed) {
            throw new AccountRuleViolation('email', (string) $decision->reason);
        }

        $token = Str::random(48);
        $invitation = DB::transaction(function () use ($actor, $account, $data, $email, $token): AccountInvitation {
            $account->invitations()->pending()->where('email', $email)->update(['revoked_at' => now()]);

            $invitation = new AccountInvitation;
            $invitation->account()->associate($account);
            $invitation->invitedBy()->associate($actor);
            $invitation->forceFill([
                'email' => $email,
                'role' => $data->role,
                'token_hash' => AccountInvitation::hashToken($token),
                'expires_at' => now()->addDays(self::EXPIRES_AFTER_DAYS),
            ])->save();

            return $invitation;
        });

        Notification::route('mail', $email)->notify(new AccountInvitationNotification($invitation, $token));
        MemberInvited::dispatch($invitation, $actor);

        return $invitation;
    }
}
