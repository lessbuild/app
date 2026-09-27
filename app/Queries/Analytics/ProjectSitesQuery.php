<?php

declare(strict_types=1);

namespace App\Queries\Analytics;

use App\Models\AnalyticsSite;
use App\Models\Project;

final class ProjectSitesQuery
{
    /** @return list<AnalyticsSite> */
    public function handle(Project $project): array
    {
        return array_values(AnalyticsSite::query()->where('project_id', $project->id)->orderBy('name')->get()->all());
    }

    /** A site of this project (404 otherwise). */
    public function find(Project $project, string|int $id): AnalyticsSite
    {
        return AnalyticsSite::query()->where('project_id', $project->id)->findOrFail($id);
    }
}
