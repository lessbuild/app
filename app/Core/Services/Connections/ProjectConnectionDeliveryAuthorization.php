<?php

namespace App\Core\Services\Connections;

use App\Core\Contracts\ProductPlanResolver;
use App\Core\Data\Connections\ProjectConnectionDeliveryAuthority;
use App\Core\Enums\ProductKey;
use App\Core\Enums\ProjectConnectionCapability;
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
        abort_unless($authority->capability === $capability->value, 403);

        $delivery = ProjectConnectionDelivery::query()
            ->whereKey($authority->deliveryId)
            ->where('project_connection_id', $authority->connectionId)
            ->where('status', 'processing')
            ->first();
        abort_unless($delivery instanceof ProjectConnectionDelivery, 403);

        $connection = ProjectConnection::query()
            ->whereKey($authority->connectionId)
            ->whereIn('status', ['pending', 'active', 'failed'])
            ->whereNull('disconnected_at')
            ->with(['project.workspace', 'sourceResource', 'targetResource'])
            ->first();
        abort_unless($connection instanceof ProjectConnection, 403);
        abort_unless(in_array($capability->value, (array) $connection->capabilities, true), 403);

        $source = $connection->sourceResource;
        $target = $connection->targetResource;
        $targetEnvironmentMatches = $targetResourceType === 'environment'
            ? $target?->environment_id !== null
                && (string) $target?->environment_id === (string) $connection->target_environment_id
            : $target?->environment_id === null && $connection->target_environment_id === null;

        abort_unless(
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
                && $connection->project_id === $target->project_id
                && $connection->project?->status === 'active'
                && $connection->project?->archived_at === null
                && $connection->project?->workspace?->status === 'active'
                && $connection->project?->workspace?->archived_at === null,
            403,
        );

        $products = [$capability->sourceProduct(), $capability->targetProduct()];
        $activeProducts = ProjectProduct::query()
            ->where('project_id', $connection->project_id)
            ->where('status', 'active')
            ->whereIn('product', $products)
            ->pluck('product')
            ->unique()
            ->all();
        abort_unless(count($activeProducts) === 2, 403);

        $workspaceId = (string) $connection->project?->workspace_id;
        $grantedProducts = WorkspaceProductAccess::query()
            ->whereIn('product', $products)
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereHas('membership', fn ($query) => $query
                ->where('workspace_id', $workspaceId)
                ->where('status', 'active'))
            ->pluck('product')
            ->unique()
            ->all();
        abort_unless(count($grantedProducts) === 2, 403);

        $sourcePlan = $this->plans->resolve($workspaceId, ProductKey::from($capability->sourceProduct()));
        $targetPlan = $this->plans->resolve($workspaceId, ProductKey::from($capability->targetProduct()));
        abort_unless($sourcePlan->available && $targetPlan->available, 403);

        $entitled = match ($capability) {
            ProjectConnectionCapability::DeploymentContext => $sourcePlan->allows('monitoring')
                && $targetPlan->hasLimit('deployment_context_minutes')
                && ($targetPlan->limit('deployment_context_minutes') === null || $targetPlan->limit('deployment_context_minutes') > 0),
            ProjectConnectionCapability::ReleaseAnnotations => $sourcePlan->allows('releases')
                && $targetPlan->allows('release_annotations'),
            ProjectConnectionCapability::IncidentAnnotations => $targetPlan->allows('incident_annotations'),
            default => false,
        };
        abort_unless($entitled, 403);

        return $delivery;
    }
}
