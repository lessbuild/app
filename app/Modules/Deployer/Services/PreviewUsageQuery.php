<?php

namespace App\Modules\Deployer\Services;

use App\Modules\Deployer\Data\PreviewLifetime;
use App\Modules\Deployer\Data\PreviewUsageSummary;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\PreviewDeployment;
use Carbon\CarbonImmutable;

class PreviewUsageQuery
{
    public const MAX_PREVIEWS = 50;

    public function __construct(private readonly PlanLimits $limits) {}

    /**
     * Read current preview quota and bounded expiry projections for a workspace.
     *
     * The active-preview predicate intentionally matches PlanLimits so that the
     * displayed usage and the enforced quota retain the same compatibility
     * behavior. This query never closes previews or dispatches cleanup.
     */
    public function for(Organization $organization): PreviewUsageSummary
    {
        $usage = $this->limits->usageForOrganization($organization, 'preview_deployments');
        $now = CarbonImmutable::now();
        $previews = $organization->previews()
            ->with('project:id,organization_id,name,preview_ttl_hours')
            ->where(fn ($query) => $query->where('status', '!=', PreviewDeployment::STATUS_CLOSED)->orWhereNull('closed_at'))
            ->orderBy('last_activity_at')
            ->limit(self::MAX_PREVIEWS)
            ->get()
            ->map(function (PreviewDeployment $preview) use ($now): PreviewLifetime {
                $project = $preview->project;
                $ttlHours = (int) $project->preview_ttl_hours;
                $expiresAt = $preview->last_activity_at->toImmutable()->addHours($ttlHours);

                return new PreviewLifetime(
                    preview: $preview,
                    project: $project,
                    ttlHours: $ttlHours,
                    expiresAt: $expiresAt,
                    expired: $expiresAt->lte($now),
                );
            })
            ->sortBy(fn (PreviewLifetime $preview): int => $preview->expiresAt->getTimestamp())
            ->values();

        return new PreviewUsageSummary(
            used: (int) $usage['used'],
            limit: $usage['limit'] === null ? null : (int) $usage['limit'],
            allowed: (bool) $usage['allowed'],
            previews: $previews,
            hiddenCount: max(0, (int) $usage['used'] - $previews->count()),
        );
    }
}
