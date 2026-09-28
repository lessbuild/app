<?php

declare(strict_types=1);

namespace App\Queries\Monitoring;

use App\Models\AlertRule;
use App\Models\Environment;
use App\Models\Project;
use App\Models\ServiceLevelObjective;
use Illuminate\Database\Eloquent\Builder;

final class ProjectAlertRulesQuery
{
    /**
     * Get the project's alert rules, enabled first.
     *
     * @param  Project  $project
     * @return list<AlertRule> the project's current rules, with their environment
     */
    public function handle(Project $project): array
    {
        return array_values(AlertRule::query()->whereIn('environment_id', $this->environments($project))
            ->with('environment')->orderByDesc('enabled')->orderBy('name')->orderBy('id')->get()->all());
    }

    /**
     * Find a rule of this project (404 otherwise). Archived rules only when asked for, e.g. to show their history.
     *
     * @param  Project  $project
     * @param  string|int  $id
     * @param  bool  $withArchived
     * @return AlertRule
     */
    public function find(Project $project, string|int $id, bool $withArchived = false): AlertRule
    {
        $query = AlertRule::query()->whereIn('environment_id', $this->environments($project));
        if ($withArchived) {
            $query->withTrashed();
        }
        $rule = $query->whereKey((int) $id)->firstOrFail();
        $rule->environment->setRelation('project', $project);

        return $rule;
    }

    /**
     * Find an objective of this project (404 otherwise).
     *
     * @param  Project  $project
     * @param  string|int  $id
     * @return ServiceLevelObjective
     */
    public function objective(Project $project, string|int $id): ServiceLevelObjective
    {
        $objective = ServiceLevelObjective::query()->whereIn('environment_id', $this->environments($project))->whereKey((int) $id)->firstOrFail();
        $objective->environment->setRelation('project', $project);

        return $objective;
    }

    /**
     * Get the project's SLOs, enabled first.
     *
     * @param  Project  $project
     * @return list<ServiceLevelObjective>
     */
    public function objectives(Project $project): array
    {
        return array_values(ServiceLevelObjective::query()->whereIn('environment_id', $this->environments($project))
            ->with('environment')->orderByDesc('enabled')->orderBy('name')->orderBy('id')->get()->all());
    }

    /**
     * Query the IDs of the project's environments, as a subquery.
     *
     * @param  Project  $project
     * @return Builder<Environment>
     */
    private function environments(Project $project): Builder
    {
        return Environment::query()->where('project_id', $project->id)->select('id');
    }
}
