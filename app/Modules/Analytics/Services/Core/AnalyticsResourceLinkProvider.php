<?php

namespace App\Modules\Analytics\Services\Core;

use App\Core\Contracts\ProjectResourceLinkProvider;
use App\Core\Data\Projects\ProjectResourceCandidate;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProjectResource;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Analytics\Models\Site;
use App\Modules\Analytics\Models\Workspace;
use App\Modules\Analytics\Services\AnalyticsWorkspaceAccess;

final class AnalyticsResourceLinkProvider implements ProjectResourceLinkProvider
{
    public function __construct(
        private readonly LegacyIdentityResolver $identities,
        private readonly AnalyticsWorkspaceAccess $access,
    ) {}

    public function candidates(PlatformUser $user): array
    {
        $sourceUserIds = $this->identities->sourceIdsFor($user, 'analytics');

        if ($sourceUserIds === []) {
            return [];
        }

        $linkedIds = ProjectResource::query()
            ->where('product', 'analytics')
            ->where('resource_type', 'site')
            ->pluck('resource_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
        $sites = $this->access->workspacesFor($user)
            ->flatMap(fn (Workspace $workspace) => $workspace->sites
                ->each(fn (Site $site) => $site->setRelation('workspace', $workspace)))
            ->reject(fn (Site $site): bool => in_array((string) $site->getKey(), $linkedIds, true))
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->take(100)
            ->values();

        return $sites->map(fn (Site $site): ProjectResourceCandidate => new ProjectResourceCandidate(
            id: (string) $site->getKey(),
            resourceType: 'site',
            name: $site->name,
            detail: $site->workspace?->name,
        ))->all();
    }

    public function candidate(PlatformUser $user, string $selectionKey): ?ProjectResourceCandidate
    {
        return collect($this->candidates($user))->first(
            fn (ProjectResourceCandidate $candidate): bool => $candidate->selectionKey() === $selectionKey,
        );
    }
}
