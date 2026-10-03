<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Data\Projects\ProjectDetails;
use App\Events\Projects\ProjectUpdated;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class UpdateProject
{
    /**
     * Rename or re-describe a project. The slug stays put so API clients and scripts keep working.
     *
     * @param  User  $actor
     * @param  Project  $project
     * @param  ProjectDetails  $details
     * @return Project
     */
    public function handle(User $actor, Project $project, ProjectDetails $details): Project
    {
        Gate::forUser($actor)->authorize('update', $project);

        $previousName = $project->name;
        $project->forceFill([
            'name' => trim($details->name),
            'description' => $details->description !== null && trim($details->description) !== '' ? trim($details->description) : null,
        ]);
        if ($project->isDirty()) {
            $project->save();
            ProjectUpdated::dispatch($project, $previousName, $actor);
        }

        return $project;
    }
}
