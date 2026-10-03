<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class DismissChecklist
{
    /**
     * Hide the getting-started checklist for everyone on the project.
     *
     * @param  User  $actor
     * @param  Project  $project
     * @return void
     */
    public function handle(User $actor, Project $project): void
    {
        Gate::forUser($actor)->authorize('update', $project);

        $project->forceFill(['checklist_dismissed_at' => now()])->save();
    }
}
