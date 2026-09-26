<?php

namespace App\Modules\Deployer\Actions\Organization;

use App\Modules\Deployer\Exceptions\OrganizationMemberOperationException;
use App\Modules\Deployer\Jobs\SyncOrganizationSeatQuantityJob;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class RemoveOrganizationMemberAction
{
    /**
     * Remove a scoped member and queue billing-seat reconciliation after the pivot write.
     *
     * @throws OrganizationMemberOperationException When the target is the workspace owner.
     * @throws ModelNotFoundException When the target is not a member of the workspace.
     */
    public function handle(Organization $organization, User $member): void
    {
        if ((int) $organization->owner_id === (int) $member->id) {
            throw new OrganizationMemberOperationException('The workspace owner cannot be removed.');
        }
        if (! $organization->members()->whereKey($member->id)->exists()) {
            throw (new ModelNotFoundException)->setModel(User::class, [$member->id]);
        }

        $organization->members()->detach($member->id);
        SyncOrganizationSeatQuantityJob::dispatch($organization->id);
    }
}
