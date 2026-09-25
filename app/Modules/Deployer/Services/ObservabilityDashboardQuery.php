<?php

namespace App\Modules\Deployer\Services;

use App\Modules\Deployer\Models\Build;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\StatusIncident;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Models\WebsiteHealthCheck;
use App\Modules\Deployer\Services\Core\DeployerProjectAccess;

class ObservabilityDashboardQuery
{
    /**
     * Load the bounded, workspace-scoped read model used by the observability dashboard.
     *
     * @return array<string, mixed> Dashboard collections; permission flags remain an HTTP concern.
     */
    public function for(Organization $organization, ?User $actor = null): array
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
            'websites' => $organization->websites()->when($actor !== null, fn ($query) => app(DeployerProjectAccess::class)->websites($query, $actor))->orderBy('name')->get(),
            'environmentProjects' => $organization->projects()
                ->when($actor !== null, fn ($query) => app(DeployerProjectAccess::class)->projects($query, $actor))
                ->with('environments:id,project_id,name,type,status,branch')
                ->select(['id', 'organization_id', 'name'])
                ->orderBy('name')
                ->get(),
            'incidents' => $incidents,
            'correlatedBuilds' => Build::query()
                ->when($actor !== null, fn ($query) => app(DeployerProjectAccess::class)->builds($query, $actor))
                ->whereHas('repository.website', fn ($query) => $query->where('organization_id', $organization->id))
                ->whereIn('status', [Build::STATUS_FAILED, Build::STATUS_CANCELED, Build::STATUS_SUCCEEDED])
                ->with('repository.website')
                ->latest('finished_at')
                ->limit(10)
                ->get(),
            'correlatedHealthChecks' => WebsiteHealthCheck::query()
                ->when($actor !== null, fn ($query) => $query->whereIn('website_id', $actor->workspaceWebsites()->select('websites.id')))
                ->whereHas('website', fn ($query) => $query->where('organization_id', $organization->id))
                ->where('successful', false)
                ->with('website:id,name')
                ->latest('checked_at')
                ->limit(10)
                ->get(),
            'servers' => $organization->servers()
                ->when($actor !== null, fn ($query) => app(DeployerProjectAccess::class)->servers($query, $actor))
                ->with(['metrics' => fn ($query) => $query->latest('recorded_at')->limit(24)])
                ->orderBy('name')
                ->get(),
            'metricRules' => $organization->metricAlertRules()->when($actor !== null, fn ($query) => $query->where(fn ($rule) => $rule->whereNull('server_id')->orWhereIn('server_id', $actor->workspaceServers()->select('servers.id'))))->with('server')->latest()->get(),
            'operationalIncidents' => $organization->operationalIncidents()
                ->when($actor !== null, fn ($query) => app(DeployerProjectAccess::class)->incidents($query, $actor))
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
