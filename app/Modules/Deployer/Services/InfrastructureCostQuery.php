<?php

namespace App\Modules\Deployer\Services;

use App\Modules\Deployer\Data\InfrastructureCostReport;
use App\Modules\Deployer\Data\InfrastructureCostRow;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\Size;

class InfrastructureCostQuery
{
    /**
     * Build the organization-scoped server estimate without invoking provider APIs.
     *
     * The monthly value is a stored provider-catalog estimate. CPU is measured
     * BuildPusher telemetry used only for an attention signal. Unknown catalog
     * rows are retained and excluded from the estimate rather than treated as
     * zero-cost infrastructure.
     */
    public function for(Organization $organization): InfrastructureCostReport
    {
        $servers = $organization->servers()
            ->withCount('websites')
            ->with([
                'provider:id,name',
                'metrics' => fn ($query) => $query->latest('recorded_at')->limit(12),
                'environments' => fn ($query) => $query
                    ->whereHas('project', fn ($project) => $project->where('organization_id', $organization->id))
                    ->with('project:id,organization_id,name')
                    ->select(['id', 'server_id', 'project_id', 'name']),
            ])
            ->orderBy('name')
            ->get();

        $sizes = Size::query()
            ->whereIn('slug', $servers->pluck('size')->filter()->unique())
            ->get(['slug', 'price_monthly', 'catalog_synced_at'])
            ->keyBy('slug');

        $rows = $servers->map(function (Server $server) use ($sizes): InfrastructureCostRow {
            $samples = $server->metrics;
            $averageCpu = $samples->whereNotNull('cpu_percent')->avg('cpu_percent');
            $size = $sizes->get($server->size);
            $monthly = $size?->price_monthly === null ? null : (float) $size->price_monthly;
            $environments = $server->environments;
            $projectNames = $environments
                ->map(fn ($environment): ?string => $environment->project?->name)
                ->filter()
                ->unique()
                ->values()
                ->all();
            $attribution = match (count($projectNames)) {
                0 => InfrastructureCostRow::ATTRIBUTION_UNALLOCATED,
                1 => InfrastructureCostRow::ATTRIBUTION_DIRECT,
                default => InfrastructureCostRow::ATTRIBUTION_SHARED,
            };

            return new InfrastructureCostRow(
                server: $server,
                monthly: $monthly,
                averageCpu: $averageCpu === null ? null : (float) $averageCpu,
                idle: $server->websites_count === 0
                    || ($samples->count() >= 6 && $averageCpu !== null && $averageCpu < 10),
                attribution: $attribution,
                environmentCount: $environments->count(),
                projectNames: $projectNames,
                catalogObservedAt: $monthly === null ? null : $size?->catalog_synced_at,
            );
        });

        return new InfrastructureCostReport(
            rows: $rows,
            estimated: (float) $rows->sum(fn (InfrastructureCostRow $row): float => $row->monthly ?? 0),
            unknownCount: $rows->filter(fn (InfrastructureCostRow $row): bool => $row->monthly === null)->count(),
            idleCount: $rows->filter(fn (InfrastructureCostRow $row): bool => $row->idle)->count(),
        );
    }
}
