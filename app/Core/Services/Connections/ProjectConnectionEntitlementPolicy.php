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
            ProjectConnectionCapability::ReleaseAnnotations => $sourcePlan->allows('releases'),
            ProjectConnectionCapability::IncidentAnnotations,
            ProjectConnectionCapability::TrafficContext => true,
        };
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
