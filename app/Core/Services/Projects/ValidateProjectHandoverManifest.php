<?php

namespace App\Core\Services\Projects;

use App\Core\Contracts\ProductPlanResolver;
use App\Core\Data\Billing\ProductPlanResolution;
use App\Core\Data\Projects\ProjectHandoverFinding;
use App\Core\Data\Projects\ProjectHandoverValidationReport;
use App\Core\Data\Projects\ProjectResourceDestinationState;
use App\Core\Enums\ProductKey;
use App\Core\Enums\ProjectConnectionCapability;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectResource;
use App\Core\Models\Workspace;
use App\Core\Services\Connections\ProjectConnectionEntitlementPolicy;
use App\Core\Services\ProjectResourceDestinationRegistry;
use App\Core\Services\ProjectResourceDestinations;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;

final class ValidateProjectHandoverManifest
{
    /**
     * Validate an uploaded manifest against the destination workspace. This is
     * intentionally read-only: a manifest is not authorization to move or
     * recreate any account, project, resource, connection, or subscription.
     */
    public function handle(
        PlatformUser $user,
        Workspace $workspace,
        string $contents,
        WorkspaceProjectAccess $access,
        ProductPlanResolver $plans,
        ProjectResourceDestinationRegistry $providerRegistry,
        ProjectResourceDestinations $destinations,
        ProjectConnectionEntitlementPolicy $connectionEntitlements,
        ProjectHandoverManifestParser $parser,
    ): ProjectHandoverValidationReport {
        if (! $access->canManageWorkspace($user, $workspace)) {
            throw new AuthorizationException;
        }

        $parseResult = $parser->parse($contents);
        if ($parseResult->status === 'invalid') {
            return $this->invalidManifest();
        }

        if ($parseResult->status === 'unsupported_schema') {
            return ProjectHandoverValidationReport::fromFindings(null, $this->emptyCounts(), [], [], [
                new ProjectHandoverFinding('blocker', 'unsupported_schema', __('This manifest version is not supported. Export a current manifest and try again.')),
            ]);
        }

        $manifest = $parseResult->manifest;
        if (! is_array($manifest)) {
            return $this->invalidManifest();
        }

        $sourceProjectId = $manifest['source']['project_id'];
        $projectSlug = $manifest['project']['slug'];
        $products = array_values(array_unique($manifest['products']));
        $environments = $manifest['environments'];
        $resources = $manifest['resources'];
        $connections = $manifest['connections'];
        $counts = [
            'environments' => count($environments),
            'resources' => count($resources),
            'connections' => count($connections),
        ];
        $findings = [];

        $sourceProject = Project::query()->whereKey($sourceProjectId)->first(['id', 'workspace_id']);
        if ($sourceProject instanceof Project && (string) $sourceProject->workspace_id !== (string) $workspace->getKey()) {
            $findings[] = new ProjectHandoverFinding(
                'review',
                'source_identity_exists',
                __('The source project identity already exists in another workspace. Review ownership and mapping before any future transfer.'),
            );
        }

        if (Project::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('slug', $projectSlug)
            ->exists()) {
            $findings[] = new ProjectHandoverFinding(
                'blocker',
                'project_conflict',
                __('A project with this slug already exists in the destination workspace.'),
            );
        }

        $membership = $access->activeMembership($user, $workspace);
        $canManageBilling = $access->canManageBilling($user, $workspace);
        $productReports = [];
        $productPlans = [];

        foreach ($products as $product) {
            $productKey = ProductKey::from($product);
            $hasAccess = $membership !== null && $access->hasProductAccess($membership, $productKey);
            $resolution = ($hasAccess || $canManageBilling)
                ? $plans->resolve((string) $workspace->getKey(), $productKey)
                : null;
            $productPlans[$product] = $resolution;
            $productReports[$product] = [
                'access' => $hasAccess,
                'plan_available' => $canManageBilling ? $resolution?->available : null,
                'limits_visible' => $canManageBilling,
                'limits' => $canManageBilling && $resolution instanceof ProductPlanResolution
                    ? $resolution->limits
                    : [],
            ];

            if (! $hasAccess) {
                $findings[] = new ProjectHandoverFinding(
                    'blocker',
                    'product_access_missing',
                    __('The destination workspace needs current access to :product before these mappings can be used.', [
                        'product' => str($product)->headline(),
                    ]),
                );

                continue;
            }

            if (! $resolution instanceof ProductPlanResolution || ! $resolution->available) {
                $findings[] = new ProjectHandoverFinding(
                    'blocker',
                    'product_plan_unavailable',
                    __('The destination workspace does not have an available :product plan for this setup.', [
                        'product' => str($product)->headline(),
                    ]),
                );
            }
        }

        $projectProbe = new Project;
        $projectProbe->forceFill(['workspace_id' => $workspace->getKey()]);
        $resourcesByReference = collect($resources)->keyBy('ref');
        $resourceProbes = $this->resourceProbes($resources);
        $destinationStates = [];
        $candidateResources = [];

        $existingResourceMappings = ProjectResource::query()
            ->whereIn('product', $products)
            ->whereIn('resource_type', array_values(array_unique(array_column($resources, 'resource_type'))))
            ->whereIn('resource_id', array_values(array_unique(array_column($resources, 'local_resource_id'))))
            ->with('project:id,workspace_id')
            ->get(['product', 'resource_type', 'resource_id', 'project_id'])
            ->mapWithKeys(fn (ProjectResource $resource): array => [$this->resourceIdentity($resource->product, $resource->resource_type, $resource->resource_id) => [
                'workspace_id' => (string) $resource->project?->workspace_id,
            ]]);

        foreach ($resources as $resource) {
            $product = $resource['product'];
            $reference = $resource['ref'];
            $hasProductAccess = $productReports[$product]['access'] ?? false;
            $plan = $productPlans[$product] ?? null;

            if (! $hasProductAccess) {
                $destinationStates[$reference] = 'permission_required';

                continue;
            }

            if (! $plan instanceof ProductPlanResolution || ! $plan->available) {
                $destinationStates[$reference] = 'plan_unavailable';

                continue;
            }

            if ($providerRegistry->get($product) === null) {
                $destinationStates[$reference] = 'provider_missing';
                $findings[] = new ProjectHandoverFinding(
                    'blocker',
                    'provider_missing',
                    __('The :product resource provider is not available on this destination.', ['product' => str($product)->headline()]),
                    $reference,
                );

                continue;
            }

            $existingMapping = $existingResourceMappings->get($this->resourceIdentity($product, $resource['resource_type'], $resource['local_resource_id']));
            if ($existingMapping !== null) {
                $isInDestinationWorkspace = $existingMapping['workspace_id'] === (string) $workspace->getKey();
                $destinationStates[$reference] = $isInDestinationWorkspace ? 'shared' : 'mapped_elsewhere';
                $findings[] = new ProjectHandoverFinding(
                    'review',
                    $isInDestinationWorkspace ? 'shared_resource' : 'resource_mapping_exists',
                    $isInDestinationWorkspace
                        ? __('This resource is already mapped in the destination workspace. Review the existing shared mapping before continuing.')
                        : __('This resource is mapped to another Buildpusher project. Review its ownership and sharing before proceeding.'),
                    $reference,
                );

                continue;
            }

            $candidateResources[$reference] = $resourceProbes[$reference];
        }

        $resolvedDestinations = $candidateResources === []
            ? []
            : $destinations->forResources($user, new Collection(array_values($candidateResources)));

        foreach ($candidateResources as $reference => $probe) {
            $destination = $resolvedDestinations[(string) $probe->getKey()] ?? null;
            $state = $destination?->state ?? ProjectResourceDestinationState::Unavailable;
            $destinationStates[$reference] = $state->value;

            if ($state !== ProjectResourceDestinationState::Available) {
                $severity = in_array($state, [ProjectResourceDestinationState::Unavailable, ProjectResourceDestinationState::Unsupported], true)
                    ? 'review'
                    : 'blocker';
                $findings[] = new ProjectHandoverFinding(
                    $severity,
                    'resource_unresolved',
                    match ($state) {
                        ProjectResourceDestinationState::AccessChanged,
                        ProjectResourceDestinationState::Missing,
                        ProjectResourceDestinationState::Stale => __('This product resource could not be confirmed for the current account.'),
                        ProjectResourceDestinationState::Unsupported => __('This product resource type has no supported destination mapping.'),
                        default => __('The product resource could not be checked because its provider is unavailable.'),
                    },
                    $reference,
                );
            }
        }

        $capabilityChecks = [];

        foreach ($connections as $connection) {
            $source = $resourcesByReference->get($connection['source_resource_ref']);
            $target = $resourcesByReference->get($connection['target_resource_ref']);

            foreach ($connection['capabilities'] as $value) {
                $capability = ProjectConnectionCapability::from($value);
                $key = $capability->value;

                if (array_key_exists($key, $capabilityChecks)) {
                    continue;
                }

                $hasAccessToProducts = ($productReports[$capability->sourceProduct()]['access'] ?? false)
                    && ($productReports[$capability->targetProduct()]['access'] ?? false);
                $allowed = $hasAccessToProducts && $connectionEntitlements->allows($projectProbe, $capability);
                $capabilityChecks[$key] = $allowed;

                if (! $allowed) {
                    $findings[] = new ProjectHandoverFinding(
                        'blocker',
                        'connection_entitlement_missing',
                        __('The destination workspace plan or product access does not allow the :capability connection.', [
                            'capability' => $capability->label(),
                        ]),
                        $connection['ref'],
                    );
                }
            }

            if (($destinationStates[$source['ref']] ?? null) !== ProjectResourceDestinationState::Available->value
                || ($destinationStates[$target['ref']] ?? null) !== ProjectResourceDestinationState::Available->value) {
                $findings[] = new ProjectHandoverFinding(
                    'blocker',
                    'connection_reference_unresolved',
                    __('A connection endpoint is not currently available in the destination.'),
                    $connection['ref'],
                );
            }
        }

        if ($manifest['omitted']['resource_mappings'] > 0 || $manifest['omitted']['connection_mappings'] > 0) {
            $findings[] = new ProjectHandoverFinding(
                'review',
                'source_mappings_omitted',
                __('The source export omitted :resources resource mapping(s) and :connections connection(s). Review them in the original apps before proceeding.', [
                    'resources' => $manifest['omitted']['resource_mappings'],
                    'connections' => $manifest['omitted']['connection_mappings'],
                ]),
            );
        }

        $resourceReports = [];
        foreach ($resources as $resource) {
            $state = $destinationStates[$resource['ref']] ?? 'unresolved';
            if (in_array($state, [
                ProjectResourceDestinationState::AccessChanged->value,
                ProjectResourceDestinationState::Missing->value,
                ProjectResourceDestinationState::Stale->value,
            ], true)) {
                $state = 'unresolved';
            }

            $resourceReports[] = [
                'reference' => $resource['ref'],
                'state' => $state,
            ];
        }

        return ProjectHandoverValidationReport::fromFindings(
            $sourceProjectId,
            $counts,
            $productReports,
            $resourceReports,
            $findings,
        );
    }

    /** @return array<string, int> */
    private function emptyCounts(): array
    {
        return ['environments' => 0, 'resources' => 0, 'connections' => 0];
    }

    private function invalidManifest(): ProjectHandoverValidationReport
    {
        return ProjectHandoverValidationReport::fromFindings(null, $this->emptyCounts(), [], [], [
            new ProjectHandoverFinding(
                'blocker',
                'invalid_manifest',
                __('This file is not a valid Buildpusher project handover manifest or contains unsafe fields.'),
            ),
        ]);
    }

    /** @param array<string, mixed> $resource */
    private function resourceProbe(array $resource): ProjectResource
    {
        $probe = new ProjectResource;
        $probe->setAttribute($probe->getKeyName(), $resource['ref']);
        $probe->forceFill([
            'product' => $resource['product'],
            'resource_type' => $resource['resource_type'],
            'resource_id' => $resource['local_resource_id'],
            'name' => $resource['name'],
            'status' => 'active',
        ]);

        return $probe;
    }

    /** @param list<array<string, mixed>> $resources
     * @return array<string, ProjectResource>
     */
    private function resourceProbes(array $resources): array
    {
        $probes = [];
        foreach ($resources as $resource) {
            $probes[$resource['ref']] = $this->resourceProbe($resource);
        }

        return $probes;
    }

    private function resourceIdentity(string $product, string $type, string $id): string
    {
        return hash('sha256', json_encode([$product, $type, $id], JSON_THROW_ON_ERROR));
    }
}
