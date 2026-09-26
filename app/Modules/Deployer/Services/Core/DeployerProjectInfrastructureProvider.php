<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\ProjectInfrastructureProvider;
use App\Core\Data\Projects\ProjectEnvironmentContext;
use App\Core\Data\Projects\ProjectInfrastructureEdge;
use App\Core\Data\Projects\ProjectInfrastructureNode;
use App\Core\Data\Projects\ProjectInfrastructureSnapshot;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project as CoreProject;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\ProjectResource;
use App\Core\Services\LegacyIdentityResolver;
use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\Project as DeployerProject;
use App\Modules\Deployer\Models\Repository;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\User as DeployerUser;
use App\Modules\Deployer\Models\Website;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\SQLiteDatabaseDoesNotExistException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use PDOException;

/** Read-only live infrastructure for a mapped Core project. */
final class DeployerProjectInfrastructureProvider implements ProjectInfrastructureProvider
{
    private const NODE_LIMIT = 100;

    private const RECENT_BUILDS_PER_ENVIRONMENT = 10;

    public function __construct(
        private readonly DeployerProjectLink $projects,
        private readonly LegacyIdentityResolver $identities,
        private readonly DeployerProjectAccess $access,
    ) {}

    public function forProject(
        PlatformUser $user,
        CoreProject $project,
        Collection $resources,
        ?ProjectEnvironmentContext $environmentContext = null,
    ): ProjectInfrastructureSnapshot {
        if ($environmentContext?->isUnavailable()) {
            return new ProjectInfrastructureSnapshot;
        }

        try {
            $nativeProject = $this->projects->projectFor($user, $project);
            $sourceIds = $this->identities->sourceIdsFor($user, 'deployer');
            if ($nativeProject === null || count($sourceIds) !== 1) {
                return new ProjectInfrastructureSnapshot;
            }

            $nativeUser = DeployerUser::query()->find($sourceIds[0]);
            if ($nativeUser === null) {
                return new ProjectInfrastructureSnapshot;
            }
            $nativeUser = $this->userForProject($nativeUser, $nativeProject);

            $environmentResources = $resources
                ->filter(fn ($resource): bool => $resource instanceof ProjectResource
                    && $resource->product === 'deployer'
                    && $resource->resource_type === 'environment'
                    && $resource->status === 'active'
                    && (string) $resource->project_id === (string) $project->getKey())
                ->sortBy(fn (ProjectResource $resource): string => (string) $resource->environment_id.'|'.(string) $resource->resource_id)
                ->values();

            if ($environmentContext?->isSelected()) {
                $environmentResources = $environmentResources
                    ->filter(fn (ProjectResource $resource): bool => (string) $resource->environment_id === (string) $environmentContext->environment?->getKey())
                    ->values();
            }

            $nodes = [];
            $edges = [];
            $environments = [];
            $truncated = false;

            foreach ($environmentResources as $mapping) {
                if (count($nodes) >= self::NODE_LIMIT) {
                    $truncated = true;
                    break;
                }

                if ($mapping->environment_id === null) {
                    continue;
                }
                $canonicalEnvironment = ProjectEnvironment::query()
                    ->whereKey($mapping->environment_id)
                    ->where('project_id', $project->getKey())
                    ->where('status', 'active')
                    ->first(['id', 'project_id', 'name']);
                if ($canonicalEnvironment === null) {
                    continue;
                }

                $authorizedEnvironment = $this->projects->accessibleEnvironment($user, $project, $mapping);
                if ($authorizedEnvironment === null) {
                    continue;
                }
                $environment = Environment::query()
                    ->whereKey($authorizedEnvironment->getKey())
                    ->where('project_id', $nativeProject->getKey())
                    ->first(['id', 'project_id', 'name', 'status', 'server_id', 'website_id']);
                if ($environment === null || ! $nativeUser->can('view', $environment)) {
                    continue;
                }

                $canonicalEnvironmentId = (string) $canonicalEnvironment->getKey();
                $environmentKey = $this->key('environment', $canonicalEnvironmentId);
                $nodes[$environmentKey] = new ProjectInfrastructureNode(
                    $environmentKey,
                    'deployer',
                    'environment',
                    (string) $environment->name,
                    $this->projectUrl($nativeProject, 'environment-'.$environment->getKey()),
                    $canonicalEnvironmentId,
                    (string) $canonicalEnvironment->name,
                    (string) $environment->status,
                );
                $environments[] = [$environment, $canonicalEnvironmentId, (string) $canonicalEnvironment->name, $environmentKey];
            }

            foreach ($environments as [$environment, $canonicalEnvironmentId, $canonicalEnvironmentName, $environmentKey]) {
                $server = $environment->server_id === null ? null : Server::query()->find($environment->server_id);
                if ($server !== null && $nativeUser->can('view', $server)
                    && $this->access->server($nativeUser, $server)) {
                    $serverKey = $this->key('server', (string) $server->getKey());
                    $this->addRelatedNode($nodes, 'server', $serverKey, (string) $server->name, $this->serverUrl($server), $canonicalEnvironmentId, $canonicalEnvironmentName, (string) $server->provisioning_status);
                    $edges[] = new ProjectInfrastructureEdge($environmentKey, $serverKey, 'runs on');
                }

                $website = $environment->website_id === null ? null : Website::query()->find($environment->website_id);
                if ($website !== null && $nativeUser->can('view', $website) && $this->access->website($nativeUser, $website)) {
                    $websiteKey = $this->key('website', (string) $website->getKey());
                    $this->addRelatedNode($nodes, 'website', $websiteKey, (string) $website->name, $this->resourceUrl('websites.show', 'website', $website->getKey(), $nativeProject), $canonicalEnvironmentId, $canonicalEnvironmentName, (string) $website->provisioning_status);
                    $edges[] = new ProjectInfrastructureEdge($environmentKey, $websiteKey, 'deploys to');

                    $repositories = $this->access->repositories(
                        Repository::query()->where('website_id', $website->getKey())->orderBy('id'),
                        $nativeUser,
                    )->limit(101)->get();
                    foreach ($repositories as $repository) {
                        if (! $nativeUser->can('view', $repository) || ! $this->access->repository($nativeUser, $repository)) {
                            continue;
                        }
                        $repositoryKey = $this->key('repository', (string) $repository->getKey());
                        $this->addRelatedNode($nodes, 'repository', $repositoryKey, (string) $repository->name, $this->resourceUrl('repositories.show', 'repository', $repository->getKey(), $nativeProject), $canonicalEnvironmentId, $canonicalEnvironmentName, null);
                        $edges[] = new ProjectInfrastructureEdge($websiteKey, $repositoryKey, 'source');
                    }
                }

                $builds = $this->access->builds(Build::query()->where('environment_id', $environment->getKey()), $nativeUser)
                    ->orderByDesc('created_at')->orderByDesc('id')->limit(self::RECENT_BUILDS_PER_ENVIRONMENT + 1)->get();
                $visibleBuilds = [];
                foreach ($builds as $build) {
                    if (! $nativeUser->can('view', $build) || ! $this->access->build($nativeUser, $build)) {
                        continue;
                    }
                    $visibleBuilds[] = $build;
                }
                if (count($visibleBuilds) > self::RECENT_BUILDS_PER_ENVIRONMENT) {
                    $truncated = true;
                    $visibleBuilds = array_slice($visibleBuilds, 0, self::RECENT_BUILDS_PER_ENVIRONMENT);
                }
                foreach ($visibleBuilds as $build) {
                    $buildKey = $this->key('deployment', (string) $build->getKey());
                    $this->addRelatedNode($nodes, 'deployment', $buildKey, $this->buildLabel($build), $this->resourceUrl('builds.show', 'build', $build->getKey(), $nativeProject), $canonicalEnvironmentId, $canonicalEnvironmentName, (string) $build->status);
                    $edges[] = new ProjectInfrastructureEdge($environmentKey, $buildKey, 'deployment');
                    $repositoryKey = $build->repository_id === null ? null : $this->key('repository', (string) $build->repository_id);
                    if ($repositoryKey !== null && isset($nodes[$repositoryKey])) {
                        $edges[] = new ProjectInfrastructureEdge($repositoryKey, $buildKey, 'built from');
                    }
                }
            }

            if (count($nodes) > self::NODE_LIMIT) {
                $nodes = array_slice($nodes, 0, self::NODE_LIMIT, true);
                $truncated = true;
            }
            $keys = array_fill_keys(array_keys($nodes), true);
            $edges = collect($edges)
                ->filter(fn (ProjectInfrastructureEdge $edge): bool => isset($keys[$edge->sourceKey], $keys[$edge->targetKey]))
                ->unique(fn (ProjectInfrastructureEdge $edge): string => $edge->sourceKey.'|'.$edge->targetKey.'|'.$edge->label)
                ->values()->all();

            return new ProjectInfrastructureSnapshot(array_values($nodes), $edges, truncated: $truncated);
        } catch (LostConnectionException|PDOException|SQLiteDatabaseDoesNotExistException) {
            return new ProjectInfrastructureSnapshot(available: false);
        }
    }

    private function userForProject(DeployerUser $user, DeployerProject $project): DeployerUser
    {
        $user = clone $user;
        $user->setAttribute('current_organization_id', $project->organization_id);
        $user->unsetRelation('currentOrganization');

        return $user;
    }

    private function node(string $kind, string $key, string $label, ?string $url, ?string $environmentId, ?string $environmentName, ?string $status): ProjectInfrastructureNode
    {
        return new ProjectInfrastructureNode($key, 'deployer', $kind, $label, $url, $environmentId, $environmentName, $status);
    }

    /** @param array<string, ProjectInfrastructureNode> $nodes */
    private function addRelatedNode(array &$nodes, string $kind, string $key, string $label, ?string $url, string $environmentId, string $environmentName, ?string $status): void
    {
        $existing = $nodes[$key] ?? null;
        if ($existing === null) {
            $nodes[$key] = $this->node($kind, $key, $label, $url, $environmentId, $environmentName, $status);

            return;
        }

        if ($existing->environmentId !== null && $existing->environmentId !== $environmentId) {
            $nodes[$key] = $this->node($kind, $key, $existing->label, $existing->url, null, null, $existing->status);
        }
    }

    private function key(string $kind, string $id): string
    {
        return 'deployer:'.$kind.':'.$id;
    }

    private function buildLabel(Build $build): string
    {
        return $build->release_name ?: ($build->revision ? substr((string) $build->revision, 0, 12) : 'Deployment #'.$build->getKey());
    }

    private function projectUrl(DeployerProject $project, string $fragment): ?string
    {
        return $this->projects->urlFor($project, $fragment);
    }

    private function serverUrl(Server $server): ?string
    {
        if (! Route::has('servers.show')) {
            return null;
        }

        return route('servers.show', ['organization_id' => $server->organization_id, 'server' => $server->getKey()]);
    }

    private function resourceUrl(string $routeName, string $parameter, string|int $id, DeployerProject $project): ?string
    {
        if (! Route::has($routeName)) {
            return null;
        }

        return route($routeName, ['organization_id' => $project->organization_id, $parameter => $id]);
    }
}
