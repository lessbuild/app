<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\ProjectResourceLinkProvider;
use App\Core\Data\Projects\ProjectResourceCandidate;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProjectResource;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\Project;
use App\Modules\Deployer\Models\User;
use Illuminate\Support\Facades\DB;

final class DeployerResourceLinkProvider implements ProjectResourceLinkProvider
{
    public function __construct(private readonly LegacyIdentityResolver $identities) {}

    public function candidates(PlatformUser $user): array
    {
        $sourceUserIds = $this->identities->sourceIdsFor($user, 'deployer');

        if ($sourceUserIds === []) {
            return [];
        }

        $sourceUsers = User::query()
            ->whereKey($sourceUserIds)
            ->whereNotNull('current_organization_id')
            ->get(['id', 'current_organization_id']);
        $organizationIds = $sourceUsers->pluck('current_organization_id')->unique()->values();

        if ($organizationIds->isEmpty()) {
            return [];
        }

        $organizations = Organization::query()
            ->whereKey($organizationIds)
            ->get(['id', 'name', 'owner_id'])
            ->keyBy('id');
        $membershipPairs = DB::connection('deployer')
            ->table('organization_user')
            ->whereIn('organization_id', $organizationIds)
            ->whereIn('user_id', $sourceUsers->modelKeys())
            ->get(['organization_id', 'user_id'])
            ->mapWithKeys(fn ($membership): array => [$membership->organization_id.':'.$membership->user_id => true]);
        $visibleOrganizationIds = $sourceUsers
            ->filter(fn (User $sourceUser): bool => isset($organizations[$sourceUser->current_organization_id])
                && ((int) $organizations[$sourceUser->current_organization_id]->owner_id === (int) $sourceUser->getKey()
                    || $membershipPairs->has($sourceUser->current_organization_id.':'.$sourceUser->getKey())))
            ->pluck('current_organization_id')
            ->unique()
            ->values();

        if ($visibleOrganizationIds->isEmpty()) {
            return [];
        }

        $linkedProjectIds = ProjectResource::query()
            ->where('product', 'deployer')
            ->where('resource_type', 'project')
            ->pluck('resource_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
        $linkedEnvironmentIds = ProjectResource::query()
            ->where('product', 'deployer')
            ->where('resource_type', 'environment')
            ->pluck('resource_id')
            ->map(static fn ($id): string => (string) $id)
            ->all();
        $projects = Project::query()
            ->whereIn('organization_id', $visibleOrganizationIds)
            ->when($linkedProjectIds !== [], fn ($query) => $query->whereNotIn('id', $linkedProjectIds))
            ->with('organization:id,name')
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'organization_id', 'name']);
        $environments = Environment::query()
            ->whereHas('project', fn ($query) => $query->whereIn('organization_id', $visibleOrganizationIds))
            ->when($linkedEnvironmentIds !== [], fn ($query) => $query->whereNotIn('id', $linkedEnvironmentIds))
            ->with('project:id,organization_id,name')
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'project_id', 'name']);

        $projectCandidates = $projects->map(fn (Project $project): ProjectResourceCandidate => new ProjectResourceCandidate(
            id: (string) $project->getKey(),
            resourceType: 'project',
            name: $project->name,
            detail: $project->organization?->name,
        ));
        $environmentCandidates = $environments->map(fn (Environment $environment): ProjectResourceCandidate => new ProjectResourceCandidate(
            id: (string) $environment->getKey(),
            resourceType: 'environment',
            name: $environment->name,
            detail: $environment->project?->name,
        ));

        return $projectCandidates->concat($environmentCandidates)->values()->all();
    }

    public function candidate(PlatformUser $user, string $selectionKey): ?ProjectResourceCandidate
    {
        return collect($this->candidates($user))->first(
            fn (ProjectResourceCandidate $candidate): bool => $candidate->selectionKey() === $selectionKey,
        );
    }
}
