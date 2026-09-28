<?php

declare(strict_types=1);

namespace App\Actions\Accounts;

use App\Events\Accounts\InvitationRevoked;
use App\Models\AccountInvitation;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class RevokeInvitation
{
    /**
     * Withdraws a pending invitation; one already accepted, revoked or expired is left alone.
     *
     * @param  User  $actor
     * @param  AccountInvitation  $invitation
     * @return void
     */
    public function handle(User $actor, AccountInvitation $invitation): void
    {
        Gate::forUser($actor)->authorize('manageMembers', $invitation->account);
        if (! $invitation->isPending()) {
            return;
        }

        $invitation->forceFill(['revoked_at' => now()])->save();
        InvitationRevoked::dispatch($invitation, $actor);
    }
}
