<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Contracts\ProjectResourceLinkProvider;
use App\Core\Data\Projects\ProjectResourceCandidate;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProjectResource;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Monitor\Models\Application;

final class MonitorResourceLinkProvider implements ProjectResourceLinkProvider
{
    public function __construct(private readonly LegacyIdentityResolver $identities) {}

    public function candidates(PlatformUser $user): array
    {
        $sourceUserIds = $this->identities->sourceIdsFor($user, 'monitor');

        if ($sourceUserIds === []) {
            return [];
        }

        $linkedIds = ProjectResource::query()
            ->where('product', 'monitor')
            ->where('resource_type', 'application')
            ->pluck('resource_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
        $applications = Application::query()
            ->when($linkedIds !== [], fn ($query) => $query->whereNotIn('id', $linkedIds))
            ->whereHas('workspace.members', fn ($members) => $members->whereIn('users.id', $sourceUserIds))
            ->with('workspace:id,name')
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'workspace_id', 'name']);

        return $applications->map(fn (Application $application): ProjectResourceCandidate => new ProjectResourceCandidate(
            id: (string) $application->getKey(),
            resourceType: 'application',
            name: $application->name,
            detail: $application->workspace?->name,
        ))->all();
    }

    public function candidate(PlatformUser $user, string $resourceId): ?ProjectResourceCandidate
    {
        return collect($this->candidates($user))->first(
            fn (ProjectResourceCandidate $candidate): bool => $candidate->id === $resourceId,
        );
    }
}
