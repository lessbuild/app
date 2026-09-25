<?php

namespace App\Modules\Monitor\Services\Core;

use App\Core\Contracts\ProjectResourceLinkProvider;
use App\Core\Data\Projects\ProjectResourceCandidate;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProjectResource;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\Environment;

final class MonitorResourceLinkProvider implements ProjectResourceLinkProvider
{
    public function __construct(
        private readonly LegacyIdentityResolver $identities,
        private readonly MonitorProjectAccess $projects,
    ) {}

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
            ->get(['id', 'workspace_id', 'name'])
            ->filter(fn (Application $application): bool => $this->projects->application($user, $application));

        $applicationCandidates = $applications->map(fn (Application $application): ProjectResourceCandidate => new ProjectResourceCandidate(
            id: (string) $application->getKey(),
            resourceType: 'application',
            name: $application->name,
            detail: $application->workspace?->name,
        ));
        $linkedEnvironmentIds = ProjectResource::query()
            ->where('product', 'monitor')
            ->where('resource_type', 'environment')
            ->pluck('resource_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
        $environments = Environment::query()
            ->whereHas('application.workspace.members', fn ($members) => $members->whereIn('users.id', $sourceUserIds))
            ->when($linkedEnvironmentIds !== [], fn ($query) => $query->whereNotIn('id', $linkedEnvironmentIds))
            ->with(['application:id,workspace_id,name', 'application.workspace:id,name'])
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'application_id', 'name'])
            ->filter(fn (Environment $environment): bool => $this->projects->environment($user, $environment));
        $environmentCandidates = $environments->map(fn (Environment $environment): ProjectResourceCandidate => new ProjectResourceCandidate(
            id: (string) $environment->getKey(),
            resourceType: 'environment',
            name: $environment->name,
            detail: collect([$environment->application?->name, $environment->application?->workspace?->name])->filter()->implode(' · '),
        ));

        return $applicationCandidates->concat($environmentCandidates)->values()->all();
    }

    public function candidate(PlatformUser $user, string $selectionKey): ?ProjectResourceCandidate
    {
        return collect($this->candidates($user))->first(
            fn (ProjectResourceCandidate $candidate): bool => $candidate->selectionKey() === $selectionKey,
        );
    }
}
