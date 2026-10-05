<?php

declare(strict_types=1);

namespace App\Queries\Projects;

use App\Data\Projects\ProjectCard;
use App\Models\Account;
use App\Models\AnalyticsDailyAggregate;
use App\Models\Build;
use App\Models\Incident;
use App\Models\Project;
use App\Models\User;
use App\Platform\ServiceRegistry;
use Carbon\CarbonImmutable;

final class AccountProjectsQuery
{
    /**
     * Create a new AccountProjectsQuery instance.
     *
     * Lists the account's projects.
     *
     * @param  ServiceRegistry  $services  Orders and names each project's enabled services.
     * @param  VisibleProjects  $visible  Limits the list to the projects the person can see.
     */
    public function __construct(private readonly ServiceRegistry $services, private readonly VisibleProjects $visible) {}

    /**
     * Get the account's projects by name, with their enabled services in registry order, environment count, health,
     * last deploy and the last ten days' visitors.
     *
     * @param  Account  $account
     * @param  User|null  $user  limits the list to the projects they can see
     * @return list<ProjectCard>
     */
    public function handle(Account $account, ?User $user = null): array
    {
        $order = array_flip($this->services->keys());
        $projects = $this->visible->scope(Project::query()->where('account_id', $account->id), $account->id, $user)
            ->with('enabledServices')
            ->withCount('environments')
            ->orderBy('name')
            ->get();
        $ids = array_values(array_map(fn (Project $project): string => $project->id, $projects->all()));
        $incidents = Incident::query()->whereIn('project_id', $ids)->whereNull('resolved_at')
            ->selectRaw('project_id, count(*) as open')->groupBy('project_id')->pluck('open', 'project_id');
        $deploys = Build::query()->join('repositories', 'repositories.id', '=', 'builds.repository_id')
            ->whereIn('repositories.project_id', $ids)->where('builds.status', Build::STATUS_SUCCEEDED)
            ->selectRaw('repositories.project_id, max(builds.finished_at) as finished')->groupBy('repositories.project_id')->pluck('finished', 'project_id');
        $visitors = $this->visitors($ids);

        return array_values($projects->map(function (Project $project) use ($order, $incidents, $deploys, $visitors): ProjectCard {
            $keys = array_values($project->enabledServices->pluck('service')
                ->filter(fn (string $key): bool => isset($order[$key]))
                ->sortBy(fn (string $key): int => $order[$key])
                ->all());
            $open = (int) ($incidents[$project->id] ?? 0);
            $deployed = $deploys[$project->id] ?? null;
            $daily = $visitors[$project->id] ?? (in_array('analytics', $keys, true) ? array_fill(0, 10, 0) : []);
            $health = match (true) {
                $open > 0 => ProjectCard::DEGRADED,
                $deployed === null && array_sum($daily) === 0 => ProjectCard::SETTING_UP,
                default => ProjectCard::HEALTHY,
            };

            return new ProjectCard(
                id: $project->id,
                name: $project->name,
                description: $project->description,
                serviceNames: array_map(fn (string $key): string => $this->services->find($key)?->name() ?? $key, $keys),
                environmentCount: (int) $project->getAttribute('environments_count'),
                serviceKeys: $keys,
                health: $health,
                healthLabel: $this->label($health),
                openIncidents: $open,
                lastDeployAt: is_string($deployed) ? CarbonImmutable::parse($deployed)->toIso8601String() : null,
                visitors: $daily,
            );
        })->all());
    }

    /**
     * Count each project's visitors on each of the last ten days (across its Analytics sites), oldest first.
     *
     * @param  list<string>  $ids
     * @return array<string, list<int>>
     */
    private function visitors(array $ids): array
    {
        $days = collect(range(9, 0))->map(fn (int $ago): string => CarbonImmutable::today()->subDays($ago)->toDateString());
        $rows = AnalyticsDailyAggregate::query()->join('analytics_sites', 'analytics_sites.id', '=', 'analytics_daily_aggregates.site_id')
            ->whereIn('analytics_sites.project_id', $ids)->where('analytics_daily_aggregates.dimension', 'all')
            ->where('analytics_daily_aggregates.local_date', '>=', $days->first())
            ->selectRaw('analytics_sites.project_id, analytics_daily_aggregates.local_date as day, sum(analytics_daily_aggregates.visitors) as total')
            ->groupBy('analytics_sites.project_id', 'analytics_daily_aggregates.local_date')
            ->get();
        $counts = [];
        foreach ($rows as $row) {
            $counts[(string) $row->getAttribute('project_id')][substr((string) $row->getAttribute('day'), 0, 10)] = (int) $row->getAttribute('total');
        }

        return array_map(fn (array $byDay): array => array_values($days->map(fn (string $day): int => $byDay[$day] ?? 0)->all()), $counts);
    }

    /**
     * Say a project's health in words.
     *
     * @param  string  $health
     * @return string
     */
    private function label(string $health): string
    {
        $label = match ($health) {
            ProjectCard::DEGRADED => __('Degraded'),
            ProjectCard::SETTING_UP => __('Setting up'),
            default => __('Healthy'),
        };

        return is_string($label) ? $label : $health;
    }
}
