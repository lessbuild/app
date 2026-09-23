<?php

namespace App\Modules\Analytics\Services\Core;

use App\Core\Contracts\ProjectProductLink;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectResource;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\User;
use Illuminate\Database\ConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;

final class AnalyticsProjectLink implements ProjectProductLink
{
    public function __construct(private readonly LegacyIdentityResolver $identities) {}

    public function resolve(PlatformUser $user, Project $project): ?string
    {
        if (! Route::has('analytics.dashboard')) {
            return null;
        }

        try {
            $resources = ProjectResource::query()
                ->where('project_id', $project->getKey())
                ->where('product', 'analytics')
                ->where('resource_type', 'site')
                ->where('status', 'active')
                ->orderBy('id')
                ->get(['resource_id']);

            foreach ($resources as $resource) {
                $site = Site::query()->find($resource->resource_id);

                if ($site === null) {
                    continue;
                }

                $workspace = $site->workspace;

                if ($workspace === null) {
                    continue;
                }

                foreach ($this->identities->sourceIdsFor($user, 'analytics') as $legacyUserId) {
                    $legacyUser = User::query()->find($legacyUserId);

                    if ($legacyUser !== null
                        && $workspace->users()->whereKey($legacyUser->getKey())->exists()) {
                        return route('analytics.dashboard', ['site' => $site->getKey()]);
                    }
                }
            }
        } catch (ConnectionException|QueryException) {
            return null;
        }

        return null;
    }
}
