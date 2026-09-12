<?php

namespace App\Services;

use App\Models\Build;
use App\Models\Organization;
use App\Models\StatusIncident;
use App\Models\WebsiteHealthCheck;

class ObservabilityDashboardQuery
{
    /**
     * Load the bounded, workspace-scoped read model used by the observability dashboard.
     *
     * @return array<string, mixed> Dashboard collections; permission flags remain an HTTP concern.
     */
    public function for(Organization $organization): array
    {
        $incidents = StatusIncident::query()
            ->whereHas('statusPage', fn ($query) => $query->where('organization_id', $organization->id))
            ->with('statusPage')
            ->latest('starts_at')
            ->limit(50)
            ->get();

        return [
            'destinations' => $organization->alertDestinations()->latest()->get(),
            'statusPages' => $organization->statusPages()->with('websites')->latest()->get(),
            'websites' => $organization->websites()->orderBy('name')->get(),
            'incidents' => $incidents,
            'correlatedBuilds' => Build::query()
                ->whereHas('repository.website', fn ($query) => $query->where('organization_id', $organization->id))
                ->whereIn('status', [Build::STATUS_FAILED, Build::STATUS_CANCELED, Build::STATUS_SUCCEEDED])
                ->with('repository.website')
                ->latest('finished_at')
                ->limit(10)
                ->get(),
            'correlatedHealthChecks' => WebsiteHealthCheck::query()
                ->whereHas('website', fn ($query) => $query->where('organization_id', $organization->id))
                ->where('successful', false)
                ->with('website:id,name')
                ->latest('checked_at')
                ->limit(10)
                ->get(),
            'servers' => $organization->servers()
                ->with(['metrics' => fn ($query) => $query->latest('recorded_at')->limit(24)])
                ->orderBy('name')
                ->get(),
            'metricRules' => $organization->metricAlertRules()->with('server')->latest()->get(),
            'operationalIncidents' => $organization->operationalIncidents()
                ->with(['assignee', 'events.actor'])
                ->latest('last_seen_at')
                ->limit(50)
                ->get(),
            'incidentResponders' => collect([$organization->owner])
                ->merge($organization->members)
                ->unique('id')
                ->sortBy('name'),
        ];
    }
}
