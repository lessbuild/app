<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Events\Accounts\InvitationAccepted;
use App\Exceptions\AccountRuleViolation;
use App\Models\AccountInvitation;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

final class AcceptInvitation
{
    /**
     * Join the invitation's account as the signed-in person, if the invitation is still pending and was sent to their
     * email, and makes it their current account. Someone who's already a member keeps their existing role.
     *
     * @param  User  $user
     * @param  string  $token
     * @return Membership
     */
    public function handle(User $user, #[SensitiveParameter] string $token): Membership
    {
        [$invitation, $membership] = DB::transaction(function () use ($user, $token): array {
            $invitation = AccountInvitation::query()
                ->where('token_hash', AccountInvitation::hashToken($token))
                ->lockForUpdate()
                ->first();
            if ($invitation === null || ! $invitation->isPending()) {
                throw AccountRuleViolation::invitationUnavailable();
            }
            if (! hash_equals($invitation->email, Str::lower($user->email))) {
                throw AccountRuleViolation::invitationForSomeoneElse();
            }
            // The account may have limited its email domains since the invitation was sent.
            if (! $invitation->account->allowsEmail($invitation->email)) {
                throw new AccountRuleViolation('invitation', __(':account no longer allows addresses at your domain.', ['account' => $invitation->account->name]));
            }

            $membership = Membership::query()->whereBelongsTo($invitation->account)->whereBelongsTo($user)->first();
            if ($membership === null) {
                $membership = new Membership;
                $membership->account()->associate($invitation->account);
                $membership->user()->associate($user);
                $membership->role = $invitation->role;
                $membership->save();
            }

            $invitation->forceFill(['accepted_at' => now()])->save();
            $user->forceFill(['current_account_id' => $invitation->account_id])->save();

            return [$invitation, $membership];
        });

        InvitationAccepted::dispatch($invitation, $membership);

        return $membership;
    }
}
