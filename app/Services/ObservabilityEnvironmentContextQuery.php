<?php

namespace App\Services;

use App\Data\ObservabilityContextFilters;
use App\Data\ObservabilityEnvironmentContext;
use App\Models\Build;
use App\Models\Environment;
use App\Models\MetricAlertRule;
use App\Models\OperationalIncident;
use App\Models\WebsiteHealthCheck;
use App\Models\WebsiteLogSnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;

class ObservabilityEnvironmentContextQuery
{
    public const MAX_BUILDS = 20;

    public const MAX_HEALTH_CHECKS = 20;

    public const MAX_INCIDENTS = 20;

    /**
     * Load explicitly related, bounded evidence for one policy-authorized environment.
     *
     * The environment policy is enforced by the Form Request before this method
     * is called. The query still constrains related records to the environment's
     * workspace and maps incident categories explicitly because incidents store
     * a category/resource ID pair rather than an environment foreign key.
     */
    public function for(Environment $environment, ObservabilityContextFilters $filters): ObservabilityEnvironmentContext
    {
        $environment->load([
            'project:id,organization_id,name',
            'website:id,organization_id,server_id,name,url,health_status,health_check_enabled',
            'server:id,organization_id,provider_id,name,display_name,provisioning_status',
        ]);

        $organizationId = (int) $environment->project->organization_id;
        $builds = $this->builds($environment, $organizationId, $filters);
        $website = $environment->website;
        $healthChecks = $website ? $this->healthChecks($website->id, $filters) : new Collection;
        $runtimeLogs = $website ? $this->runtimeLogs($website->id) : new Collection;
        $incidentIds = $this->relatedIncidentIds($environment, $organizationId, $builds);

        return new ObservabilityEnvironmentContext(
            environment: $environment,
            window: $filters->window,
            since: $filters->since,
            builds: $builds,
            healthChecks: $healthChecks,
            runtimeLogs: $runtimeLogs,
            incidents: $this->incidents($organizationId, $incidentIds, $filters),
        );
    }

    /**
     * Load recent environment builds, retaining active attempts regardless of age for recovery visibility.
     *
     * @return Collection<int, Build> Bounded build metadata without deployment logs or environment payloads.
     */
    private function builds(Environment $environment, int $organizationId, ObservabilityContextFilters $filters): Collection
    {
        return $environment->builds()
            ->whereHas('repository', fn (Builder $query) => $query->where('organization_id', $organizationId))
            ->where(function (Builder $query) use ($filters): void {
                $query->where('builds.created_at', '>=', $filters->since)
                    ->orWhereIn('builds.status', Build::ACTIVE_STATUSES);
            })
            ->with('repository:id,organization_id,website_id,name')
            ->select([
                'builds.id',
                'builds.repository_id',
                'builds.environment_id',
                'builds.status',
                'builds.revision',
                'builds.trigger_source',
                'builds.setup_stage',
                'builds.created_at',
                'builds.started_at',
                'builds.finished_at',
            ])
            ->latest('builds.created_at')
            ->latest('builds.id')
            ->limit(self::MAX_BUILDS)
            ->get();
    }

    /**
     * Load recent website health observations without error text or endpoint details.
     *
     * @return Collection<int, WebsiteHealthCheck> Bounded health metadata.
     */
    private function healthChecks(int $websiteId, ObservabilityContextFilters $filters): Collection
    {
        return WebsiteHealthCheck::query()
            ->where('website_id', $websiteId)
            ->where('checked_at', '>=', $filters->since)
            ->select(['id', 'website_id', 'successful', 'source', 'http_status', 'duration_ms', 'checked_at'])
            ->latest('checked_at')
            ->latest('id')
            ->limit(self::MAX_HEALTH_CHECKS)
            ->get();
    }

    /**
     * Load only current snapshot metadata; encrypted runtime output remains behind the existing policy route.
     *
     * @return Collection<int, WebsiteLogSnapshot> At most one metadata row per supported log type.
     */
    private function runtimeLogs(int $websiteId): Collection
    {
        return WebsiteLogSnapshot::query()
            ->where('website_id', $websiteId)
            ->whereIn('type', WebsiteLogSnapshot::TYPES)
            ->select(['id', 'website_id', 'type', 'status', 'refreshed_at'])
            ->orderBy('type')
            ->get();
    }

    /**
     * Map only incident categories with a concrete environment relationship.
     *
     * @return array<string, BaseCollection<int, int>> Explicit category-to-resource IDs.
     */
    private function relatedIncidentIds(Environment $environment, int $organizationId, Collection $builds): array
    {
        $website = $environment->website;
        $server = $environment->server;
        $metricRuleIds = $server
            ? MetricAlertRule::query()
                ->where('organization_id', $organizationId)
                ->where('server_id', $server->id)
                ->pluck('id')
            : collect();

        return [
            'deployment' => $builds->pluck('id'),
            'website' => $website ? collect([$website->id]) : collect(),
            'server' => $server ? collect([$server->id]) : collect(),
            'metric' => $metricRuleIds,
            'scheduled_task' => $environment->scheduledTasks()->pluck('id'),
            'provider' => $server?->provider_id ? collect([$server->provider_id]) : collect(),
        ];
    }

    /**
     * Load current incidents plus recent resolved incidents from the same workspace.
     *
     * @param  array<string, BaseCollection<int, int>>  $relatedIds  Explicit category/resource relationships.
     * @return Collection<int, OperationalIncident> Bounded incident metadata without encrypted evidence bodies.
     */
    private function incidents(int $organizationId, array $relatedIds, ObservabilityContextFilters $filters): Collection
    {
        return OperationalIncident::query()
            ->where('organization_id', $organizationId)
            ->where(function (Builder $query) use ($relatedIds): void {
                $query->whereIn('category', []);
                foreach ($relatedIds as $category => $ids) {
                    if ($ids->isEmpty()) {
                        continue;
                    }

                    $query->orWhere(fn (Builder $related): Builder => $related
                        ->where('category', $category)
                        ->whereIn('resource_id', $ids->all()));
                }
            })
            ->where(function (Builder $query) use ($filters): void {
                $query->whereIn('status', [OperationalIncident::STATUS_OPEN, OperationalIncident::STATUS_ACKNOWLEDGED])
                    ->orWhere('last_seen_at', '>=', $filters->since);
            })
            ->with('assignee:id,name')
            ->select([
                'id',
                'organization_id',
                'assigned_to',
                'category',
                'resource_id',
                'status',
                'severity',
                'title',
                'occurrences',
                'detected_at',
                'last_seen_at',
                'resolved_at',
            ])
            ->latest('last_seen_at')
            ->latest('id')
            ->limit(self::MAX_INCIDENTS)
            ->get();
    }
}
