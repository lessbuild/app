<?php

declare(strict_types=1);

namespace App\Domain\Projects\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Events\ProjectDeleted;
use App\Domain\Projects\Models\Project;
use Illuminate\Support\Facades\Gate;

final class DeleteProject
{
    /** Delete a project with its environments and enabled services. Services clean up their own data from ProjectDeleted. */
    public function handle(User $actor, Project $project): void
    {
        Gate::forUser($actor)->authorize('delete', $project);

        $project->delete();
        ProjectDeleted::dispatch($project->id, $project->account_id, $project->name, $actor);
    }
}
