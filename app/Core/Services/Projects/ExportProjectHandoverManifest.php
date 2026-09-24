<?php

namespace App\Core\Services\Projects;

use App\Core\Data\Projects\ProjectResourceDestinationState;
use App\Core\Enums\ProjectConnectionCapability;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectConnection;
use App\Core\Models\ProjectEnvironment;
use App\Core\Models\ProjectProduct;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace;
use App\Core\Services\ProjectResourceDestinations;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final class ExportProjectHandoverManifest
{
    public const SCHEMA = 'buildpusher.project-handover';

    public const VERSION = 1;

    /** @var array<string, string> */
    private const CONFIGURATION_KINDS = [
        'deployer' => 'deployment_configuration',
        'monitor' => 'monitoring_configuration',
        'analytics' => 'analytics_site_configuration',
    ];

    /**
     * Export only stable, currently authorized project references. This is a
     * handover manifest, not a backup or a transfer operation.
     *
     * @return array<string, mixed>
     */
    public function handle(
        PlatformUser $user,
        Workspace $workspace,
        Project $project,
        WorkspaceProjectAccess $access,
        ProjectResourceDestinations $destinations,
    ): array {
        if ((string) $project->workspace_id !== (string) $workspace->getKey()) {
            throw (new ModelNotFoundException)->setModel(Project::class, [$project->getKey()]);
        }

        if ($project->status !== 'active' || $project->archived_at !== null) {
            throw (new ModelNotFoundException)->setModel(Project::class, [$project->getKey()]);
        }

        if (! $access->canManageWorkspace($user, $workspace)) {
            throw new AuthorizationException;
        }

        $membership = $access->activeMembership($user, $workspace);
        $activeProducts = ProjectProduct::query()
            ->where('project_id', $project->getKey())
            ->where('status', 'active')
            ->pluck('product')
            ->filter(fn (string $product): bool => $membership !== null && $access->hasProductAccess($membership, $product))
            ->unique()
            ->values()
            ->all();

        $environments = ProjectEnvironment::query()
            ->where('project_id', $project->getKey())
            ->where('status', 'active')
            ->orderBy('slug')
            ->get();
        $environmentIds = $environments->mapWithKeys(fn (ProjectEnvironment $environment): array => [
            (string) $environment->getKey() => 'environment_'.$environment->getKey(),
        ]);

        $mappedResources = ProjectResource::query()
            ->where('project_id', $project->getKey())
            ->where('status', 'active')
            ->whereIn('product', $activeProducts)
            ->where(function ($query) use ($environmentIds): void {
                $query->whereNull('environment_id');

                if ($environmentIds->isNotEmpty()) {
                    $query->orWhereIn('environment_id', $environmentIds->keys());
                }
            })
            ->get();
        $resourceDestinations = $destinations->forResources($user, $mappedResources);
        $exportableResources = $mappedResources
            ->filter(fn (ProjectResource $resource): bool => ($resourceDestinations[(string) $resource->getKey()]->state ?? null) === ProjectResourceDestinationState::Available)
            ->values();
        $resourceReferences = $exportableResources->mapWithKeys(fn (ProjectResource $resource): array => [
            (string) $resource->getKey() => 'resource_'.$resource->getKey(),
        ]);

        $resourceEntries = $exportableResources->map(function (ProjectResource $resource) use ($environmentIds, $resourceReferences): array {
            return [
                'ref' => $resourceReferences[(string) $resource->getKey()],
                'product' => $resource->product,
                'resource_type' => $resource->resource_type,
                'local_resource_id' => (string) $resource->resource_id,
                'name' => $resource->name,
                'environment_ref' => $resource->environment_id === null
                    ? null
                    : ($environmentIds[(string) $resource->environment_id] ?? null),
            ];
        })->all();

        $connections = ProjectConnection::query()
            ->where('project_id', $project->getKey())
            ->whereNull('disconnected_at')
            ->where('status', '!=', 'disconnected')
            ->with(['sourceResource', 'targetResource'])
            ->get()
            ->filter(function (ProjectConnection $connection) use ($resourceReferences, $environmentIds): bool {
                if (! isset($resourceReferences[(string) $connection->source_resource_id], $resourceReferences[(string) $connection->target_resource_id])) {
                    return false;
                }

                if (($connection->source_environment_id !== null && ! $environmentIds->has((string) $connection->source_environment_id))
                    || ($connection->target_environment_id !== null && ! $environmentIds->has((string) $connection->target_environment_id))) {
                    return false;
                }

                $source = $connection->sourceResource;
                $target = $connection->targetResource;

                if (! $source instanceof ProjectResource
                    || ! $target instanceof ProjectResource
                    || (string) $source->project_id !== (string) $connection->project_id
                    || (string) $target->project_id !== (string) $connection->project_id) {
                    return false;
                }

                foreach ((array) $connection->capabilities as $capabilityValue) {
                    $capability = is_string($capabilityValue) ? ProjectConnectionCapability::tryFrom($capabilityValue) : null;

                    if ($capability === null || ! $capability->supportsResources($source, $target)) {
                        return false;
                    }
                }

                return count((array) $connection->capabilities) > 0;
            })
            ->map(function (ProjectConnection $connection) use ($resourceReferences, $environmentIds): array {
                return [
                    'ref' => 'connection_'.$connection->getKey(),
                    'source_resource_ref' => $resourceReferences[(string) $connection->source_resource_id],
                    'target_resource_ref' => $resourceReferences[(string) $connection->target_resource_id],
                    'source_environment_ref' => $connection->source_environment_id === null
                        ? null
                        : $environmentIds[(string) $connection->source_environment_id],
                    'target_environment_ref' => $connection->target_environment_id === null
                        ? null
                        : $environmentIds[(string) $connection->target_environment_id],
                    'capabilities' => array_values($connection->capabilities ?? []),
                ];
            })
            ->values()
            ->all();

        $configurationReferences = $exportableResources
            ->map(function (ProjectResource $resource) use ($resourceReferences): array {
                return [
                    'product' => $resource->product,
                    'resource_ref' => $resourceReferences[(string) $resource->getKey()],
                    'kind' => self::CONFIGURATION_KINDS[$resource->product],
                    'values_included' => false,
                ];
            })
            ->all();

        $productInstructions = [
            'deployer' => [__('Reconnect authorized repositories and deployment targets in Deployer; provide secrets separately.')],
            'monitor' => [__('Confirm application ownership, ingestion credentials, checks, and alert routes in Monitor.')],
            'analytics' => [__('Confirm site ownership, domain verification, collection credentials, and event configuration in Analytics.')],
        ];

        $omittedResources = max(0, ProjectResource::query()
            ->where('project_id', $project->getKey())
            ->where('status', 'active')
            ->count() - $exportableResources->count());
        $omittedConnections = max(0, ProjectConnection::query()
            ->where('project_id', $project->getKey())
            ->whereNull('disconnected_at')
            ->where('status', '!=', 'disconnected')
            ->count() - count($connections));

        return [
            'schema' => self::SCHEMA,
            'version' => self::VERSION,
            'exported_at' => now()->toIso8601String(),
            'source' => [
                'workspace_id' => (string) $workspace->getKey(),
                'workspace_slug' => $workspace->slug,
                'project_id' => (string) $project->getKey(),
                'project_slug' => $project->slug,
            ],
            'ownership' => [
                'workspace_owner_user_id' => $workspace->owner_user_id,
                'project_creator_user_id' => $project->created_by_user_id,
            ],
            'project' => [
                'name' => $project->name,
                'slug' => $project->slug,
            ],
            'products' => array_values($activeProducts),
            'environments' => $environments->map(fn (ProjectEnvironment $environment): array => [
                'ref' => $environmentIds[(string) $environment->getKey()],
                'name' => $environment->name,
                'slug' => $environment->slug,
                'type' => $environment->environment_type,
            ])->all(),
            'resources' => $resourceEntries,
            'connections' => $connections,
            'configuration_references' => $configurationReferences,
            'setup_instructions' => collect($activeProducts)
                ->mapWithKeys(fn (string $product): array => [$product => $productInstructions[$product] ?? []])
                ->all(),
            'omitted' => [
                'resource_mappings' => $omittedResources,
                'connection_mappings' => $omittedConnections,
                'reason' => __('Some mappings were omitted because their app access, environment, or source could not be confirmed.'),
            ],
            'security' => [
                'secrets_included' => false,
                'authentication_credentials_included' => false,
                'environment_values_included' => false,
                'subscriptions_included' => false,
                'arbitrary_metadata_included' => false,
                'is_backup' => false,
                'note' => __('This manifest contains authorized references only. Reconnect credentials and verify product configuration separately; subscriptions stay with their workspace.'),
            ],
        ];
    }
}
