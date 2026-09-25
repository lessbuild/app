<?php

namespace App\Modules\Deployer\Services;

use App\Core\Models\PlatformUser;
use App\Core\Services\Auth\ProductAuthentication;
use App\Core\Services\Identity\ProductWorkspaceAccess;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PersonalOrganization
{
    public function __construct(
        private readonly ProductAuthentication $authentication,
        private readonly ProductWorkspaceAccess $workspaceAccess,
    ) {}

    /**
     * Resolve or create the user's current workspace under a user-row lock.
     *
     * @param  User  $user  The persisted account whose workspace attributes and relation are refreshed.
     * @return Organization The existing workspace, or a new personal workspace with an owner membership.
     */
    public function ensure(User $user): Organization
    {
        return DB::connection('deployer')->transaction(function () use ($user): Organization {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $usesCoreAuthority = $this->authentication->usesCoreAuthority('deployer');

            if ($locked->current_organization_id) {
                $organization = Organization::query()->findOrFail($locked->current_organization_id);
                if (! $usesCoreAuthority || $this->hasCoreAccess($locked, $organization)) {
                    $user->setAttribute('current_organization_id', $organization->id);
                    $user->setRelation('currentOrganization', $organization);

                    return $organization;
                }
            }

            if ($usesCoreAuthority) {
                $organization = $this->firstCoreAccessibleOrganization($locked);
                abort_if($organization === null, 403, 'This account does not have access to a mapped Deployer workspace.');
                $locked->update(['current_organization_id' => $organization->getKey()]);
                $user->setAttribute('current_organization_id', $organization->getKey());
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

    private function firstCoreAccessibleOrganization(User $user): ?Organization
    {
        $platformUser = $this->platformUser($user);
        if ($platformUser === null) {
            return null;
        }

        return Organization::query()
            ->where(fn ($query) => $query
                ->where('owner_id', $user->getKey())
                ->orWhereHas('members', fn ($members) => $members->whereKey($user->getKey())))
            ->orderBy('id')
            ->get()
            ->first(fn (Organization $organization): bool => $this->workspaceAccess->allows(
                $platformUser,
                'deployer',
                'organization',
                $organization->getKey(),
            ));
    }

    private function hasCoreAccess(User $user, Organization $organization): bool
    {
        $platformUser = $this->platformUser($user);

        return $platformUser !== null && $this->workspaceAccess->allows(
            $platformUser,
            'deployer',
            'organization',
            $organization->getKey(),
        );
    }

    private function platformUser(User $user): ?PlatformUser
    {
        $platformUserId = $user->platform_user_id;

        return is_string($platformUserId) && $platformUserId !== ''
            ? PlatformUser::query()->find($platformUserId)
            : null;
    }
}
