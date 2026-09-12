<?php

namespace App\Actions\Organization;

use App\Jobs\SyncOrganizationSeatQuantityJob;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\PlanLimits;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AcceptOrganizationInvitationAction
{
    public function __construct(private readonly PlanLimits $limits) {}

    /**
     * Verify and consume a workspace invitation, switch the actor's workspace, and queue seat reconciliation.
     *
     * Both the initial check and the locked check intentionally remain: the former preserves the existing
     * fast 403 response while the latter prevents replay and seat races from accepting stale invitations.
     *
     * @throws AuthorizationException When token, expiry, or invited-email identity is invalid.
     */
    public function handle(User $actor, OrganizationInvitation $invitation, string $token): void
    {
        $this->assertUsable($actor, $invitation, $token);
        DB::transaction(function () use ($actor, $invitation, $token): void {
            $organization = Organization::query()->lockForUpdate()->findOrFail($invitation->organization_id);
            $lockedInvitation = OrganizationInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            $this->assertUsable($actor, $lockedInvitation, $token);
            $this->limits->enforceForOrganization($organization, 'members');
            $organization->members()->syncWithoutDetaching([$actor->id => ['role' => $lockedInvitation->role]]);
            $lockedInvitation->update(['accepted_at' => now()]);
        }, 3);
        $actor->update(['current_organization_id' => $invitation->organization_id]);
        SyncOrganizationSeatQuantityJob::dispatch($invitation->organization_id);
    }

    /**
     * Keep token and email comparisons identical at the initial and locked acceptance boundaries.
     */
    private function assertUsable(User $actor, OrganizationInvitation $invitation, string $token): void
    {
        if (! $invitation->isUsable()
            || ! hash_equals($invitation->token_hash, hash('sha256', $token))
            || Str::lower($actor->email) !== Str::lower($invitation->email)) {
            throw new AuthorizationException;
        }
    }
}
