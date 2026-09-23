<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Contracts\ProjectProductLink;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectResource;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\User;
use Illuminate\Database\ConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;

final class MonitorProjectLink implements ProjectProductLink
{
    public function __construct(private readonly LegacyIdentityResolver $identities) {}

    public function resolve(PlatformUser $user, Project $project): ?string
    {
        if (! Route::has('monitor.applications.show')) {
            return null;
        }

        try {
            $resources = ProjectResource::query()
                ->where('project_id', $project->getKey())
                ->where('product', 'monitor')
                ->where('resource_type', 'application')
                ->where('status', 'active')
                ->orderBy('id')
                ->get(['resource_id']);

            foreach ($resources as $resource) {
                $application = Application::query()->find($resource->resource_id);

                if ($application === null) {
                    continue;
                }

                $workspace = $application->workspace;

                if ($workspace === null) {
                    continue;
                }

                foreach ($this->identities->sourceIdsFor($user, 'monitor') as $legacyUserId) {
                    $legacyUser = User::query()->find($legacyUserId);

                    if ($legacyUser !== null
                        && $workspace->members()->whereKey($legacyUser->getKey())->exists()) {
                        return route('monitor.applications.show', $application->getKey());
                    }
                }
            }
        } catch (ConnectionException|QueryException) {
            return null;
        }

        return null;
    }
}
