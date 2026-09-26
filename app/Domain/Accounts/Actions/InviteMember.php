<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Data\InviteMemberData;
use App\Domain\Accounts\Events\MemberInvited;
use App\Domain\Accounts\Exceptions\AccountRuleViolation;
use App\Domain\Accounts\Models\Account;
use App\Domain\Accounts\Models\AccountInvitation;
use App\Domain\Accounts\Notifications\AccountInvitationNotification;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

final class InviteMember
{
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
