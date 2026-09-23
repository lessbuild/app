<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Contracts\ProjectProductLink;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectResource;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Monitor\Models\Application;
use Illuminate\Database\ConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
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
            $application = $this->accessibleApplications($user, $project)->first();

            if ($application !== null) {
                return route('monitor.applications.show', $application->getKey());
            }
        } catch (ConnectionException|QueryException) {
            return null;
        }

        return null;
    }

    /** @return Collection<int, Application> */
    public function accessibleApplications(PlatformUser $user, Project $project): Collection
    {
        $resourceIds = ProjectResource::query()
            ->where('project_id', $project->getKey())
            ->where('product', 'monitor')
            ->where('resource_type', 'application')
            ->where('status', 'active')
            ->orderBy('id')
            ->limit(100)
            ->pluck('resource_id');

        if ($resourceIds->isEmpty()) {
            return collect();
        }

        $legacyUserIds = $this->identities->sourceIdsFor($user, 'monitor');
        if ($legacyUserIds === []) {
            return collect();
        }

        return Application::query()
            ->whereKey($resourceIds)
            ->whereHas('workspace.members', fn ($members) => $members->whereIn('users.id', $legacyUserIds))
            ->with('workspace')
            ->get()
            ->values();
    }
}
