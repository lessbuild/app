<?php

namespace App\Modules\Analytics\Services\Core;

use App\Core\Contracts\ProjectResourceDestinationProvider;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProjectResource;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Analytics\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

final class AnalyticsResourceDestinationProvider implements ProjectResourceDestinationProvider
{
    public function __construct(private readonly LegacyIdentityResolver $identities) {}

    public function destinations(PlatformUser $user, Collection $resources): array
    {
        if (! Route::has('analytics.dashboard')) {
            return [];
        }

        $mappingIdsByProductResource = $resources
            ->where('resource_type', 'site')
            ->mapWithKeys(fn (ProjectResource $resource): array => [
                (string) $resource->resource_id => (string) $resource->getKey(),
            ]);
        $sourceUserIds = $this->identities->sourceIdsFor($user, 'analytics');

        if ($mappingIdsByProductResource->isEmpty() || $sourceUserIds === []) {
            return [];
        }

        return Site::query()
            ->whereKey($mappingIdsByProductResource->keys())
            ->whereHas('workspace.users', fn ($users) => $users->whereIn('users.id', $sourceUserIds))
            ->get(['id'])
            ->mapWithKeys(fn (Site $site): array => [
                $mappingIdsByProductResource->get((string) $site->getKey()) => route('analytics.dashboard', ['site' => $site->getKey()]),
            ])
            ->all();
    }
}
