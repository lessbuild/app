<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Contracts\ProjectResourceDestinationProvider;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProjectResource;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Monitor\Models\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

final class MonitorResourceDestinationProvider implements ProjectResourceDestinationProvider
{
    public function __construct(private readonly LegacyIdentityResolver $identities) {}

    public function destinations(PlatformUser $user, Collection $resources): array
    {
        if (! Route::has('monitor.applications.show')) {
            return [];
        }

        $mappingIdsByProductResource = $resources
            ->where('resource_type', 'application')
            ->mapWithKeys(fn (ProjectResource $resource): array => [
                (string) $resource->resource_id => (string) $resource->getKey(),
            ]);
        $sourceUserIds = $this->identities->sourceIdsFor($user, 'monitor');

        if ($mappingIdsByProductResource->isEmpty() || $sourceUserIds === []) {
            return [];
        }

        return Application::query()
            ->whereKey($mappingIdsByProductResource->keys())
            ->whereHas('workspace.members', fn ($members) => $members->whereIn('users.id', $sourceUserIds))
            ->get(['id'])
            ->mapWithKeys(fn (Application $application): array => [
                $mappingIdsByProductResource->get((string) $application->getKey()) => route('monitor.applications.show', $application->getKey()),
            ])
            ->all();
    }
}
