<?php

namespace App\Core\Services\Connections;

use App\Core\Contracts\ProductPlanResolver;
use App\Core\Enums\ProductKey;
use App\Core\Enums\ProjectConnectionCapability;
use App\Core\Models\Project;

final class ProjectConnectionEntitlementPolicy
{
    public function __construct(private readonly ProductPlanResolver $plans) {}

    public function allows(Project $project, ProjectConnectionCapability $capability): bool
    {
        if ($capability === ProjectConnectionCapability::TrafficContext) {
            return $this->trafficContextWindowMinutes($project) !== null;
        }

        if (! $capability->hasDeliveryHandler()) {
            return false;
        }

        $workspaceId = (string) $project->workspace_id;
        $sourcePlan = $this->plans->resolve($workspaceId, ProductKey::from($capability->sourceProduct()));
        $targetPlan = $this->plans->resolve($workspaceId, ProductKey::from($capability->targetProduct()));

        if (! $sourcePlan->available || ! $targetPlan->available) {
            return false;
        }

        return match ($capability) {
            ProjectConnectionCapability::DeploymentContext => $sourcePlan->allows('monitoring')
                && $targetPlan->hasLimit('deployment_context_minutes')
                && ($targetPlan->limit('deployment_context_minutes') === null || $targetPlan->limit('deployment_context_minutes') > 0),
            ProjectConnectionCapability::ReleaseAnnotations => $sourcePlan->allows('releases')
                && $targetPlan->allows('release_annotations'),
            ProjectConnectionCapability::IncidentAnnotations => $targetPlan->allows('incident_annotations'),
            ProjectConnectionCapability::TrafficContext => false,
        };
    }

    public function trafficContextWindowMinutes(Project $project): ?int
    {
        $analyticsPlan = $this->plans->resolve((string) $project->workspace_id, ProductKey::Analytics);
        $monitorPlan = $this->plans->resolve((string) $project->workspace_id, ProductKey::Monitor);

        if (! $analyticsPlan->available
            || ! $monitorPlan->available
            || ! $analyticsPlan->allows('traffic_context')
            || ! $monitorPlan->hasLimit('deployment_context_minutes')) {
            return null;
        }

        $limit = $monitorPlan->limit('deployment_context_minutes');

        if ($limit !== null && $limit <= 0) {
            return null;
        }

        return min($limit ?? 240, 240);
    }

    /** @return list<ProjectConnectionCapability> */
    public function availableFor(Project $project): array
    {
        return array_values(array_filter(
            ProjectConnectionCapability::cases(),
            fn (ProjectConnectionCapability $capability): bool => $this->allows($project, $capability),
        ));
    }
}
