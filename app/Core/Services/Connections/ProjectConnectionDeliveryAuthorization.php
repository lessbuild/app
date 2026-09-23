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
        abort_unless($authority->capability === ProjectConnectionCapability::DeploymentContext->value, 403);

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
        abort_unless(in_array(ProjectConnectionCapability::DeploymentContext->value, (array) $connection->capabilities, true), 403);

        $source = $connection->sourceResource;
        $target = $connection->targetResource;
        abort_unless(
            $source instanceof ProjectResource
                && $source->product === ProductKey::Deployer->value
                && $source->resource_type === 'environment'
                && (string) $source->resource_id === (string) data_get($delivery->payload, 'source_environment_id')
                && (string) $source->environment_id === (string) $connection->source_environment_id
                && $source->status === 'active'
                && $target instanceof ProjectResource
                && $target->product === ProductKey::Monitor->value
                && $target->resource_type === 'environment'
                && (string) $target->resource_id === $targetEnvironmentId
                && $target->status === 'active'
                && (string) $target->environment_id === (string) $connection->target_environment_id
                && $connection->project_id === $source->project_id
                && $connection->project_id === $target->project_id
                && $connection->project?->status === 'active'
                && $connection->project?->archived_at === null
                && $connection->project?->workspace?->status === 'active'
                && $connection->project?->workspace?->archived_at === null,
            403,
        );

        $activeProducts = ProjectProduct::query()
            ->where('project_id', $connection->project_id)
            ->where('status', 'active')
            ->whereIn('product', [ProductKey::Deployer->value, ProductKey::Monitor->value])
            ->pluck('product')
            ->all();
        abort_unless(count(array_unique($activeProducts)) === 2, 403);

        $workspaceId = (string) $connection->project?->workspace_id;
        $grantedProducts = WorkspaceProductAccess::query()
            ->whereIn('product', [ProductKey::Deployer->value, ProductKey::Monitor->value])
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

        $deployerPlan = $this->plans->resolve($workspaceId, ProductKey::Deployer);
        $monitorPlan = $this->plans->resolve($workspaceId, ProductKey::Monitor);
        abort_unless($deployerPlan->allows('monitoring'), 403);
        abort_unless(
            $monitorPlan->hasLimit('deployment_context_minutes')
                && ($monitorPlan->limit('deployment_context_minutes') === null || $monitorPlan->limit('deployment_context_minutes') > 0),
            403,
        );

        return $delivery;
    }
}
