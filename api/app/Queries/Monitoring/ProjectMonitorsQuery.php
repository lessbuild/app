<?php

declare(strict_types=1);

namespace App\Queries\Monitoring;

use App\Models\Environment;
use App\Models\Monitor;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;

final class ProjectMonitorsQuery
{
    /**
     * Get the project's monitors by name, with their environment.
     *
     * @param  Project  $project
     * @return list<Monitor> the project's current monitors, with their environment
     */
    public function handle(Project $project): array
    {
        return array_values($this->scope($project)->with('environment')->orderBy('name')->orderBy('id')->get()->all());
    }

    /**
     * Find a monitor of this project (404 otherwise). Archived monitors only when asked for, e.g. to show their
     * history.
     *
     * @param  Project  $project
     * @param  string|int  $id
     * @param  bool  $withArchived
     * @return Monitor
     */
    public function find(Project $project, string|int $id, bool $withArchived = false): Monitor
    {
        $query = $this->scope($project);
        if ($withArchived) {
            $query->withTrashed();
        }
        $monitor = $query->whereKey((int) $id)->firstOrFail();
        $monitor->environment->setRelation('project', $project);

        return $monitor;
    }

    /**
     * Query the monitors of the project's environments.
     *
     * @param  Project  $project
     * @return Builder<Monitor>
     */
    private function scope(Project $project): Builder
    {
        return Monitor::query()->whereIn('environment_id', Environment::query()->where('project_id', $project->id)->select('id'));
    }
}
