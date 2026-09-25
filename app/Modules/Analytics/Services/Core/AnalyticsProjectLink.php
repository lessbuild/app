<?php

namespace App\Modules\Analytics\Services\Core;

use App\Core\Contracts\ProjectProductLink;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectResource;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Services\AnalyticsWorkspaceAccess;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

final class AnalyticsProjectLink implements ProjectProductLink
{
    public function __construct(
        private readonly LegacyIdentityResolver $identities,
        private readonly AnalyticsWorkspaceAccess $access,
    ) {}

    public function resolve(PlatformUser $user, Project $project): ?string
    {
        if (! Route::has('analytics.dashboard')) {
            return null;
        }

        try {
            $site = $this->accessibleSites($user, $project)->first();

            if ($site !== null) {
                return route('analytics.dashboard', ['site' => $site->getKey()]);
            }
        } catch (LostConnectionException|QueryException) {
            return null;
        }

        return null;
    }

    /** @return Collection<int, Site> */
    public function accessibleSites(PlatformUser $user, Project $project): Collection
    {
        $resourceIds = ProjectResource::query()
            ->where('project_id', $project->getKey())
            ->where('product', 'analytics')
            ->where('resource_type', 'site')
            ->where('status', 'active')
            ->orderBy('id')
            ->pluck('resource_id');

        if ($resourceIds->isEmpty() || ! filled($project->workspace_id)) {
            return collect();
        }

        $workspaceIds = $this->identities->sourceIdsForCanonical(
            'analytics',
            'workspace',
            $project->workspace_id,
            'workspace',
        );

        if ($workspaceIds === []) {
            return collect();
        }

        $legacyUserIds = $this->identities->sourceIdsFor($user, 'analytics');
        if ($legacyUserIds === []) {
            return collect();
        }

        $sites = Site::query()
            ->whereKey($resourceIds)
            ->whereIn('workspace_id', $workspaceIds)
            ->whereHas('workspace.users', fn ($users) => $users->whereIn('users.id', $legacyUserIds))
            ->with('workspace')
            ->orderBy('id')
            ->get();

        return $this->access->filterSites($user, $sites)->take(100)->values();
    }
}
