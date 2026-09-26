<?php

declare(strict_types=1);

namespace App\Domain\Projects\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Data\ProjectDetails;
use App\Domain\Projects\Events\ProjectUpdated;
use App\Domain\Projects\Models\Project;
use Illuminate\Support\Facades\Gate;

final class UpdateProject
{
    /** Rename or re-describe a project. The slug stays put so API clients and scripts keep working. */
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
