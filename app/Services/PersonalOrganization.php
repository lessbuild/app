<?php

namespace App\Services;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PersonalOrganization
{
    /**
     * Resolve or create the user's current workspace under a user-row lock.
     *
     * @param  User  $user  The persisted account whose workspace attributes and relation are refreshed.
     * @return Organization The existing workspace, or a new personal workspace with an owner membership.
     */
    public function ensure(User $user): Organization
    {
        return DB::transaction(function () use ($user): Organization {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            if ($locked->current_organization_id) {
                $organization = Organization::query()->findOrFail($locked->current_organization_id);
                $user->setAttribute('current_organization_id', $organization->id);
                $user->setRelation('currentOrganization', $organization);

                return $organization;
            }

            $organization = Organization::query()->create([
                'owner_id' => $locked->id,
                'name' => ($locked->name ?: 'Personal').' Workspace',
                'slug' => (Str::slug($locked->name ?: Str::before($locked->email, '@')) ?: 'workspace').'-'.$locked->id,
            ]);
            $organization->members()->attach($locked->id, ['role' => 'owner']);
            $locked->update(['current_organization_id' => $organization->id]);
            $user->setAttribute('current_organization_id', $organization->id);
            $user->setRelation('currentOrganization', $organization);

            return $organization;
        });
    }
}
