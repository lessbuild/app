<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class DismissChecklist
{
    /**
     * Hides the getting-started checklist for everyone on the project.
     */
    public function handle(User $actor, Project $project): void
    {
        Gate::forUser($actor)->authorize('update', $project);

        $project->forceFill(['checklist_dismissed_at' => now()])->save();
    }
}
