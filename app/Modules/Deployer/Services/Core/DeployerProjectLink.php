<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\ProjectProductLink;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\ProjectResource;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Deployer\Models\Project;
use App\Modules\Deployer\Models\User;
use Illuminate\Database\ConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;

final class DeployerProjectLink implements ProjectProductLink
{
    public function __construct(private readonly LegacyIdentityResolver $identities) {}

    public function resolve(PlatformUser $user, CoreProject $project): ?string
    {
        if (! Route::has('projects.show')) {
            return null;
        }

        try {
            $resource = ProjectResource::query()
                ->where('project_id', $project->getKey())
                ->where('product', 'deployer')
                ->where('resource_type', 'project')
                ->where('status', 'active')
                ->first();

            if ($resource === null) {
                return null;
            }

            $legacyProject = Project::query()->with('organization')->find($resource->resource_id);

            if ($legacyProject === null || $legacyProject->organization === null) {
                return null;
            }

            foreach ($this->identities->sourceIdsFor($user, 'deployer') as $legacyUserId) {
                $legacyUser = User::query()->find($legacyUserId);

                if ($legacyUser === null
                    || (int) $legacyUser->current_organization_id !== (int) $legacyProject->organization_id
                    || ! $legacyProject->organization->permits($legacyUser, 'view')) {
                    continue;
                }

                return route('projects.show', $legacyProject->getKey());
            }
        } catch (ConnectionException|QueryException) {
            return null;
        }

        return null;
    }
}
