<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Events\Projects\ProjectDeleted;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class DeleteProject
{
    /**
     * Delete a project with its environments and enabled services. Services clean up their own data from ProjectDeleted.
     *
     * @param  User  $actor
     * @param  Project  $project
     * @return void
     */
    public function handle(User $actor, Project $project): void
    {
        Gate::forUser($actor)->authorize('delete', $project);

        $project->delete();
        ProjectDeleted::dispatch($project->id, $project->account_id, $project->name, $actor);
    }
}
