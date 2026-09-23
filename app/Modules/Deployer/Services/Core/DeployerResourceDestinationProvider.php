<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\ProjectResourceDestinationProvider;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProjectResource;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Deployer\Models\Project;
use App\Modules\Deployer\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

final class DeployerResourceDestinationProvider implements ProjectResourceDestinationProvider
{
    public function __construct(private readonly LegacyIdentityResolver $identities) {}

    public function destinations(PlatformUser $user, Collection $resources): array
    {
        if (! Route::has('projects.show')) {
            return [];
        }

        $mappingIdsByProductResource = $resources
            ->where('resource_type', 'project')
            ->mapWithKeys(fn (ProjectResource $resource): array => [
                (string) $resource->resource_id => (string) $resource->getKey(),
            ]);
        $sourceUserIds = $this->identities->sourceIdsFor($user, 'deployer');

        if ($mappingIdsByProductResource->isEmpty() || $sourceUserIds === []) {
            return [];
        }

        $sourceUsers = User::query()
            ->whereKey($sourceUserIds)
            ->whereNotNull('current_organization_id')
            ->get(['id', 'current_organization_id'])
            ->keyBy(fn (User $sourceUser): string => (string) $sourceUser->getKey());
        $projects = Project::query()
            ->whereKey($mappingIdsByProductResource->keys())
            ->with('organization:id,owner_id')
            ->get(['id', 'organization_id']);

        $membershipPairs = DB::connection('deployer')
            ->table('organization_user')
            ->whereIn('organization_id', $projects->pluck('organization_id')->unique())
            ->whereIn('user_id', $sourceUsers->keys())
            ->get(['organization_id', 'user_id'])
            ->mapWithKeys(fn ($membership): array => [
                $membership->organization_id.':'.$membership->user_id => true,
            ]);

        $destinations = [];

        foreach ($projects as $project) {
            $canView = $sourceUsers->contains(function (User $sourceUser) use ($membershipPairs, $project): bool {
                if ((int) $sourceUser->current_organization_id !== (int) $project->organization_id
                    || $project->organization === null) {
                    return false;
                }

                return (int) $project->organization->owner_id === (int) $sourceUser->getKey()
                    || $membershipPairs->has($project->organization_id.':'.$sourceUser->getKey());
            });

            if ($canView) {
                $mappingId = $mappingIdsByProductResource->get((string) $project->getKey());
                $destinations[$mappingId] = route('projects.show', $project->getKey());
            }
        }

        return $destinations;
    }
}
