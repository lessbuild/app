<?php

namespace App\Modules\Analytics\Services\Core;

use App\Core\Contracts\ProjectProductLink;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectResource;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Analytics\Models\Site;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
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
            ->limit(100)
            ->pluck('resource_id');

        if ($resourceIds->isEmpty()) {
            return collect();
        }

        $legacyUserIds = $this->identities->sourceIdsFor($user, 'analytics');
        if ($legacyUserIds === []) {
            return collect();
        }

        return Site::query()
            ->whereKey($resourceIds)
            ->whereHas('workspace.users', fn ($users) => $users->whereIn('users.id', $legacyUserIds))
            ->with('workspace')
            ->get()
            ->values();
    }
}
