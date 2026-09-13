<?php

namespace App\Services;

use App\Data\ApplicationEnvironmentOverview;
use App\Models\Environment;
use App\Models\Project;
use Illuminate\Support\Collection;

class ApplicationConfigurationEnvironmentOverviewQuery
{
    /**
     * Load the bounded, project-scoped recorded topology for the configuration authoring page.
     *
     * Commands, variable keys and encrypted resource configuration are deliberately not read
     * into the display model. This is local recorded state, not a remote drift observation.
     *
     * @return Collection<int, ApplicationEnvironmentOverview> Secret-safe environment summaries.
     */
    public function for(Project $project): Collection
    {
        return $project->environments()
            ->with([
                'server:id,name,provisioning_status',
                'website:id,name,provisioning_status,health_status',
                'website.repositories:id,website_id,name,branch',
                'website.repositories.latestBuild' => fn ($query) => $query->select(['builds.id', 'builds.repository_id', 'builds.status']),
                'processes:id,environment_id,name,type,replicas,is_enabled',
                'resources:id,environment_id,name,type,is_managed,status',
                'variables:id,environment_id,is_secret',
            ])
            ->orderByRaw("CASE type WHEN 'preview' THEN 0 WHEN 'development' THEN 1 WHEN 'staging' THEN 2 WHEN 'production' THEN 3 ELSE 4 END")
            ->orderBy('name')
            ->get()
            ->map(fn ($environment): ApplicationEnvironmentOverview => $this->summarize($environment));
    }

    /**
     * Convert one eager-loaded environment into display metadata without touching secret values.
     *
     * @param  Environment  $environment  The project-scoped environment with its recorded dependencies.
     * @return ApplicationEnvironmentOverview Secret-safe environment state.
     */
    private function summarize(Environment $environment): ApplicationEnvironmentOverview
    {
        $repository = $environment->website?->repositories->firstWhere('branch', $environment->branch);
        $dependencies = [
            [
                'kind' => 'server',
                'name' => $environment->server?->name ?: 'Not attached',
                'status' => $environment->server?->provisioning_status ?: 'missing',
                'detail' => 'Compute target',
            ],
            [
                'kind' => 'website',
                'name' => $environment->website?->name ?: 'Not attached',
                'status' => $environment->website?->provisioning_status ?: 'missing',
                'detail' => $environment->website?->health_status ? 'Health: '.$environment->website->health_status : 'Application target',
            ],
            [
                'kind' => 'repository',
                'name' => $repository?->name ?: 'Not connected',
                'status' => $repository?->latestBuild?->status ?: 'never_deployed',
                'detail' => $repository ? 'Branch: '.$repository->branch : 'Source control target',
            ],
        ];

        foreach ($environment->processes as $process) {
            $dependencies[] = [
                'kind' => 'process',
                'name' => $process->name,
                'status' => $process->is_enabled ? 'enabled' : 'disabled',
                'detail' => ucfirst((string) $process->type).' · '.$process->replicas.' replica(s)',
            ];
        }
        foreach ($environment->resources as $resource) {
            $dependencies[] = [
                'kind' => 'resource',
                'name' => $resource->name,
                'status' => $resource->status,
                'detail' => ($resource->is_managed ? 'Managed' : 'External').' · '.ucfirst(str_replace('_', ' ', (string) $resource->type)),
            ];
        }
        if ($environment->variables->isNotEmpty()) {
            $dependencies[] = [
                'kind' => 'variables',
                'name' => 'Environment variables',
                'status' => 'configured',
                'detail' => $environment->variables->count().' configured · '.$environment->variables->where('is_secret', true)->count().' secret value(s) masked',
            ];
        }

        return new ApplicationEnvironmentOverview(
            id: (int) $environment->id,
            name: (string) $environment->name,
            type: (string) $environment->type,
            status: (string) $environment->status,
            branch: (string) $environment->branch,
            runtimeType: (string) ($environment->runtime_type ?: 'php'),
            isProtected: (bool) $environment->is_protected,
            processCount: $environment->processes->count(),
            resourceCount: $environment->resources->count(),
            variableCount: $environment->variables->count(),
            secretCount: $environment->variables->where('is_secret', true)->count(),
            dependencies: $dependencies,
        );
    }
}
