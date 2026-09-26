<?php

declare(strict_types=1);

namespace App\Domain\Accounts\Actions;

use App\Domain\Accounts\Events\InvitationRevoked;
use App\Domain\Accounts\Models\AccountInvitation;
use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Gate;

final class RevokeInvitation
{
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
