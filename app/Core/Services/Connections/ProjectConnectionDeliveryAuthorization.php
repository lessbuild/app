<?php

namespace App\Core\Services\Connections;

use App\Core\Contracts\ProductPlanResolver;
use App\Core\Data\Billing\ProductPlanResolution;
use App\Core\Data\Connections\ProjectConnectionDeliveryAuthority;
use App\Core\Enums\ProductKey;
use App\Core\Enums\ProjectConnectionCapability;
use App\Core\Exceptions\Connections\ProjectConnectionDeliveryBlocked;
use App\Core\Models\ProjectConnection;
use App\Core\Models\ProjectConnectionDelivery;
use App\Core\Models\ProjectProduct;
use App\Core\Models\ProjectResource;
use App\Core\Models\WorkspaceProductAccess;

final class ProjectConnectionDeliveryAuthorization
{
    public function __construct(private readonly ProductPlanResolver $plans) {}

    public function assertDeploymentContext(
        ProjectConnectionDeliveryAuthority $authority,
        string $targetEnvironmentId,
    ): ProjectConnectionDelivery {
        return $this->assertTarget(
            $authority,
            ProjectConnectionCapability::DeploymentContext,
            'environment',
            $targetEnvironmentId,
        );
    }

    public function assertReleaseAnnotation(
        ProjectConnectionDeliveryAuthority $authority,
        string $targetSiteId,
    ): ProjectConnectionDelivery {
        return $this->assertTarget(
            $authority,
            ProjectConnectionCapability::ReleaseAnnotations,
            'site',
            $targetSiteId,
        );
    }

    public function assertIncidentAnnotation(
        ProjectConnectionDeliveryAuthority $authority,
        string $targetSiteId,
    ): ProjectConnectionDelivery {
        return $this->assertTarget(
            $authority,
            ProjectConnectionCapability::IncidentAnnotations,
            'site',
            $targetSiteId,
        );
    }

    private function assertTarget(
        ProjectConnectionDeliveryAuthority $authority,
        ProjectConnectionCapability $capability,
        string $targetResourceType,
        string $targetResourceId,
    ): ProjectConnectionDelivery {
        $this->blockUnless($authority->capability === $capability->value, 'unsupported_capability');

        $delivery = ProjectConnectionDelivery::query()
            ->whereKey($authority->deliveryId)
            ->where('project_connection_id', $authority->connectionId)
            ->where('status', 'processing')
            ->first();
        $this->blockUnless($delivery instanceof ProjectConnectionDelivery, 'delivery_unavailable');

        $connection = ProjectConnection::query()
            ->whereKey($authority->connectionId)
            ->whereIn('status', ['pending', 'active', 'failed'])
            ->whereNull('disconnected_at')
            ->with(['project.workspace', 'sourceResource', 'targetResource'])
            ->first();
        $this->blockUnless($connection instanceof ProjectConnection, 'connection_unavailable');
        $this->blockUnless(in_array($capability->value, (array) $connection->capabilities, true), 'unsupported_capability');

        if ($connection->automation_paused_at !== null) {
            throw new ProjectConnectionDeliveryBlocked('automation_paused');
        }

        $source = $connection->sourceResource;
        $target = $connection->targetResource;
        $targetEnvironmentMatches = $targetResourceType === 'environment'
            ? $target?->environment_id !== null
                && (string) $target?->environment_id === (string) $connection->target_environment_id
            : $target?->environment_id === null && $connection->target_environment_id === null;

        $resourcesMatch =
            $source instanceof ProjectResource
                && $source->product === $capability->sourceProduct()
                && $source->resource_type === 'environment'
                && (string) $source->resource_id === (string) data_get($delivery->payload, 'source_environment_id')
                && (string) $source->environment_id === (string) $connection->source_environment_id
                && $source->status === 'active'
                && $target instanceof ProjectResource
                && $target->product === $capability->targetProduct()
                && $target->resource_type === $targetResourceType
                && (string) $target->resource_id === $targetResourceId
                && $target->status === 'active'
                && $targetEnvironmentMatches
                && $connection->project_id === $source->project_id
                && $connection->project_id === $target->project_id;
        $this->blockUnless($resourcesMatch, 'resource_mapping_changed');

        $project = $connection->project;
        $workspace = $project?->workspace;
        $this->blockUnless(
            $project?->status === 'active'
                && $project?->archived_at === null
                && $workspace?->status === 'active'
                && $workspace?->archived_at === null,
            'project_unavailable',
        );

        $products = [$capability->sourceProduct(), $capability->targetProduct()];
        $activeProducts = ProjectProduct::query()
            ->where('project_id', $connection->project_id)
            ->where('status', 'active')
            ->whereIn('product', $products)
            ->pluck('product')
            ->unique()
            ->all();
        $this->blockUnless(count($activeProducts) === 2, 'product_not_enabled');

        $workspaceId = (string) $project->workspace_id;
        $grantedProducts = WorkspaceProductAccess::query()
            ->whereIn('product', $products)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereHas('membership', fn ($query) => $query
                ->where('workspace_id', $workspaceId)
                ->currentlyActive())
            ->pluck('product')
            ->unique()
            ->all();
        $this->blockUnless(count($grantedProducts) === 2, 'product_access_changed');

        $sourcePlan = $this->plans->resolve($workspaceId, ProductKey::from($capability->sourceProduct()));
        $targetPlan = $this->plans->resolve($workspaceId, ProductKey::from($capability->targetProduct()));
        $this->blockUnless($sourcePlan->available && $targetPlan->available, 'product_subscription_unavailable');

        switch ($capability) {
            case ProjectConnectionCapability::DeploymentContext:
                $this->authorizeDeploymentContextPlan($sourcePlan, $targetPlan);
                break;
            case ProjectConnectionCapability::ReleaseAnnotations:
                $this->blockUnless(
                    $sourcePlan->allows('releases') && $targetPlan->allows('release_annotations'),
                    'product_feature_not_included',
                );
                break;
            case ProjectConnectionCapability::IncidentAnnotations:
                $this->blockUnless(
                    $targetPlan->allows('incident_annotations'),
                    'product_feature_not_included',
                );
                break;
            case ProjectConnectionCapability::TrafficContext:
                $this->blockUnless(false, 'unsupported_capability');
        }

        return $delivery;
    }

    private function authorizeDeploymentContextPlan(
        ProductPlanResolution $sourcePlan,
        ProductPlanResolution $targetPlan,
    ): void {
        $this->blockUnless(
            $sourcePlan->allows('monitoring') && $targetPlan->hasLimit('deployment_context_minutes'),
            'product_feature_not_included',
        );

        $limit = $targetPlan->limit('deployment_context_minutes');
        $this->blockUnless($limit === null || $limit > 0, 'product_limit_unavailable');
    }

    private function blockUnless(bool $condition, string $reasonCode): void
    {
        if (! $condition) {
            throw new ProjectConnectionDeliveryBlocked($reasonCode);
        }
    }
}
