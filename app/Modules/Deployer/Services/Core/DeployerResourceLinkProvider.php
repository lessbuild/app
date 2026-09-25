<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\ProjectResourceLinkProvider;
use App\Core\Data\Projects\ProjectResourceCandidate;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProjectResource;
use App\Core\Services\Auth\ProductAuthentication;
use App\Core\Services\Identity\MappedProjectResourceAccess;
use App\Core\Services\Identity\ProductWorkspaceAccess;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\Project;
use App\Modules\Deployer\Models\User;
use Illuminate\Support\Facades\DB;

final class DeployerResourceLinkProvider implements ProjectResourceLinkProvider
{
    public function __construct(
        private readonly LegacyIdentityResolver $identities,
        private readonly ProductAuthentication $authentication,
        private readonly ProductWorkspaceAccess $workspaceAccess,
    ) {}

    public function candidates(PlatformUser $user): array
    {
        $sourceUserIds = $this->identities->sourceIdsFor($user, 'deployer');

        if ($sourceUserIds === []) {
            return [];
        }

        $usesCoreAuthority = $this->authentication->usesCoreAuthority('deployer');
        if ($usesCoreAuthority && count($sourceUserIds) !== 1) {
            return [];
        }

        $sourceUsersQuery = User::query()->whereKey($sourceUserIds);
        if (! $usesCoreAuthority) {
            $sourceUsersQuery->whereNotNull('current_organization_id');
        }
        $sourceUsers = $sourceUsersQuery->get(['id', 'current_organization_id']);
        $organizationIds = $usesCoreAuthority
            ? LegacyIdentityMap::query()
                ->where('source_product', 'deployer')
                ->where('source_entity', 'organization')
                ->where('canonical_entity', 'workspace')
                ->where('status', 'reconciled')
                ->pluck('source_id')
                ->map(static fn ($id): string => (string) $id)
                ->unique()
                ->values()
            : $sourceUsers->pluck('current_organization_id')->unique()->values();

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
        $visibleOrganizationIds = $organizations
            ->filter(function (Organization $organization) use ($membershipPairs, $sourceUsers, $usesCoreAuthority, $user): bool {
                foreach ($sourceUsers as $sourceUser) {
                    if (! $usesCoreAuthority && (int) $sourceUser->current_organization_id !== (int) $organization->getKey()) {
                        continue;
                    }

                    $hasLocalMembership = (int) $organization->owner_id === (int) $sourceUser->getKey()
                        || $membershipPairs->has($organization->getKey().':'.$sourceUser->getKey());
                    if (! $hasLocalMembership) {
                        continue;
                    }

                    if (! $usesCoreAuthority || $this->workspaceAccess->allows($user, 'deployer', 'organization', $organization->getKey())) {
                        return true;
                    }
                }

                return false;
            })
            ->keys()
            ->values();

        if ($visibleOrganizationIds->isEmpty()) {
            return [];
        }

        $accessibleProjectIds = [];
        foreach ($visibleOrganizationIds as $organizationId) {
            $candidates = Project::query()->where('organization_id', $organizationId)->pluck('id')->all();
            $denied = app(MappedProjectResourceAccess::class)->deniedResourceIds(
                $user, 'deployer', 'project', 'organization', $organizationId, $candidates,
            );
            if ($denied !== null) {
                $accessibleProjectIds = [...$accessibleProjectIds, ...array_diff(array_map('strval', $candidates), $denied)];
            }
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
            ->whereIn('id', $accessibleProjectIds)
            ->when($linkedProjectIds !== [], fn ($query) => $query->whereNotIn('id', $linkedProjectIds))
            ->with('organization:id,name')
            ->orderBy('name')
            ->limit(100)
            ->get(['id', 'organization_id', 'name']);
        $environments = Environment::query()
            ->whereIn('project_id', $accessibleProjectIds)
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
