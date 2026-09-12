<?php

namespace App\Actions\Organization;

use App\Exceptions\OrganizationMemberOperationException;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class UpdateOrganizationMemberAction
{
    /**
     * Update a scoped member role while preserving owner protection and member-not-found concealment.
     *
     * @throws OrganizationMemberOperationException When the target is the workspace owner.
     * @throws ModelNotFoundException When the target is not a member of the workspace.
     */
    public function handle(Organization $organization, User $member, string $role): void
    {
        if ((int) $organization->owner_id === (int) $member->id) {
            throw new OrganizationMemberOperationException('The owner role cannot be changed.');
        }
        if (! $organization->members()->whereKey($member->id)->exists()) {
            throw (new ModelNotFoundException)->setModel(User::class, [$member->id]);
        }

        $organization->members()->updateExistingPivot($member->id, ['role' => $role]);
    }
}
