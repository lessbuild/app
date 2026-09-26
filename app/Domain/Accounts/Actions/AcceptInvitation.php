<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Events\InvitationAccepted;
use App\Domain\Accounts\Exceptions\AccountRuleViolation;
use App\Domain\Accounts\Models\AccountInvitation;
use App\Domain\Accounts\Models\Membership;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use SensitiveParameter;

final class AcceptInvitation
{
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
