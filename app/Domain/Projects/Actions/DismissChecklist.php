<?php

declare(strict_types=1);

namespace App\Domain\Projects\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Models\Project;
use Illuminate\Support\Facades\Gate;

final class DismissChecklist
{
    public function handle(User $actor, Project $project): void
    {
        Gate::forUser($actor)->authorize('update', $project);

        $project->forceFill(['checklist_dismissed_at' => now()])->save();
    }
}
