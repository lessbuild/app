<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Models\Project as CoreProject;
use App\Core\Models\ProjectResource;
use App\Modules\Deployer\Models\Environment;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\Website;
use Illuminate\Support\Collection;

/** Resolve Deployer websites and servers only when their active Core mappings are unambiguous. */
final class DeployerOperationalResourceMap
{
    /**
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, organization_id: int}>  $mappedEnvironments
     * @return array<string, array{project: CoreProject, environment: Environment, label: string, organization_id: int}>
     */
    public function websites(array $mappedEnvironments): array
    {
        $candidates = collect($mappedEnvironments)
            ->filter(fn (array $mapping): bool => $mapping['environment']->website_id !== null);

        if ($candidates->isEmpty()) {
            return [];
        }

        $websiteIds = $candidates
            ->map(fn (array $mapping): string => (string) $mapping['environment']->website_id)
            ->unique()
            ->values();
        $websites = Website::query()
            ->whereIn('id', $websiteIds)
            ->get(['id', 'organization_id'])
            ->keyBy(fn (Website $website): string => (string) $website->getKey());
        $environmentsByWebsite = Environment::query()
            ->whereIn('website_id', $websiteIds)
            ->with('project:id,organization_id')
            ->get(['id', 'website_id', 'project_id'])
            ->groupBy('website_id');
        $environmentIds = $environmentsByWebsite
            ->flatten(1)
            ->map(fn (Environment $environment): string => (string) $environment->getKey())
            ->unique()
            ->values();
        $coreMappingsByEnvironment = ProjectResource::query()
            ->where('product', 'deployer')
            ->where('resource_type', 'environment')
            ->where('status', 'active')
            ->whereIn('resource_id', $environmentIds)
            ->get(['id', 'project_id', 'resource_id'])
            ->groupBy('resource_id');
        $safe = [];
        $ambiguous = [];

        foreach ($candidates as $mapping) {
            $websiteId = (string) $mapping['environment']->website_id;
            $website = $websites->get($websiteId);

            if ($website === null || (int) $website->organization_id !== $mapping['organization_id']) {
                continue;
            }

            $projectOrganizationPairs = $environmentsByWebsite
                ->get($websiteId, collect())
                ->flatMap(function (Environment $environment) use ($coreMappingsByEnvironment): Collection {
                    $organizationId = $environment->project?->organization_id;
                    $coreMappings = $coreMappingsByEnvironment->get((string) $environment->getKey(), collect());

                    if ($organizationId === null) {
                        return $coreMappings->isEmpty()
                            ? collect()
                            : collect(['unresolved:'.(string) $environment->getKey()]);
                    }

                    return $coreMappings
                        ->map(fn (ProjectResource $resource): string => (string) $resource->project_id.':'.(string) $organizationId);
                })
                ->unique()
                ->values();

            if ($projectOrganizationPairs->count() !== 1
                || $projectOrganizationPairs->first() !== (string) $mapping['project']->getKey().':'.$mapping['organization_id']) {
                continue;
            }

            if (isset($ambiguous[$websiteId])) {
                continue;
            }

            if (isset($safe[$websiteId])
                && ((string) $safe[$websiteId]['project']->getKey() !== (string) $mapping['project']->getKey()
                    || $safe[$websiteId]['organization_id'] !== $mapping['organization_id'])) {
                unset($safe[$websiteId]);
                $ambiguous[$websiteId] = true;

                continue;
            }

            $safe[$websiteId] = $mapping;
        }

        return $safe;
    }

    /**
     * @param  array<string, array{project: CoreProject, environment: Environment, label: string, organization_id: int}>  $mappedEnvironments
     * @return array<string, array{project: CoreProject, environment: Environment, label: string, organization_id: int}>
     */
    public function servers(array $mappedEnvironments): array
    {
        $candidates = collect($mappedEnvironments)
            ->filter(fn (array $mapping): bool => $mapping['environment']->server_id !== null);

        if ($candidates->isEmpty()) {
            return [];
        }

        $serverIds = $candidates
            ->map(fn (array $mapping): string => (string) $mapping['environment']->server_id)
            ->unique()
            ->values();
        $servers = Server::query()
            ->whereIn('id', $serverIds)
            ->get(['id', 'organization_id'])
            ->keyBy(fn (Server $server): string => (string) $server->getKey());
        $environmentsByServer = Environment::query()
            ->whereIn('server_id', $serverIds)
            ->with('project:id,organization_id')
            ->get(['id', 'server_id', 'project_id'])
            ->groupBy('server_id');
        $environmentIds = $environmentsByServer
            ->flatten(1)
            ->map(fn (Environment $environment): string => (string) $environment->getKey())
            ->unique()
            ->values();
        $coreMappingsByEnvironment = ProjectResource::query()
            ->where('product', 'deployer')
            ->where('resource_type', 'environment')
            ->where('status', 'active')
            ->whereIn('resource_id', $environmentIds)
            ->get(['id', 'project_id', 'resource_id'])
            ->groupBy('resource_id');
        $safe = [];
        $ambiguous = [];

        foreach ($candidates as $mapping) {
            $serverId = (string) $mapping['environment']->server_id;
            $server = $servers->get($serverId);

            if ($server === null || (int) $server->organization_id !== $mapping['organization_id']) {
                continue;
            }

            $projectOrganizationPairs = $environmentsByServer
                ->get($serverId, collect())
                ->flatMap(function (Environment $environment) use ($coreMappingsByEnvironment): Collection {
                    $organizationId = $environment->project?->organization_id;
                    $coreMappings = $coreMappingsByEnvironment->get((string) $environment->getKey(), collect());

                    if ($organizationId === null) {
                        return $coreMappings->isEmpty()
                            ? collect()
                            : collect(['unresolved:'.(string) $environment->getKey()]);
                    }

                    return $coreMappings
                        ->map(fn (ProjectResource $resource): string => (string) $resource->project_id.':'.(string) $organizationId);
                })
                ->unique()
                ->values();

            if ($projectOrganizationPairs->count() !== 1
                || $projectOrganizationPairs->first() !== (string) $mapping['project']->getKey().':'.$mapping['organization_id']) {
                continue;
            }

            if (isset($ambiguous[$serverId])) {
                continue;
            }

            if (isset($safe[$serverId])
                && ((string) $safe[$serverId]['project']->getKey() !== (string) $mapping['project']->getKey()
                    || $safe[$serverId]['organization_id'] !== $mapping['organization_id'])) {
                unset($safe[$serverId]);
                $ambiguous[$serverId] = true;

                continue;
            }

            $safe[$serverId] = $mapping;
        }

        return $safe;
    }
}
