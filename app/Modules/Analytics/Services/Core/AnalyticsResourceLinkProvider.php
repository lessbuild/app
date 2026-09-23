<?php

namespace App\Modules\Analytics\Services\Core;

use App\Core\Contracts\ProjectResourceLinkProvider;
use App\Core\Data\Projects\ProjectResourceCandidate;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProjectResource;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Analytics\Models\Site;

final class AnalyticsResourceLinkProvider implements ProjectResourceLinkProvider
{
    public function __construct(private readonly LegacyIdentityResolver $identities) {}

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
        $sites = Site::query()
            ->when($linkedIds !== [], fn ($query) => $query->whereNotIn('id', $linkedIds))
            ->whereHas('workspace.users', fn ($users) => $users->whereIn('users.id', $sourceUserIds))
            ->with('workspace:id,name')
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'workspace_id', 'name']);

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
