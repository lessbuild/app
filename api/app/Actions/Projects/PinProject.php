<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Models\Project;
use App\Models\ProjectPin;
use App\Models\User;

final class PinProject
{
    /**
     * Pin a project to the top of the person's projects list, or unpin it.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  bool  $pinned
     * @return void
     */
    public function handle(User $user, Project $project, bool $pinned): void
    {
        $existing = ProjectPin::query()->where('user_id', $user->id)->where('project_id', $project->id);
        if (! $pinned) {
            $existing->delete();

            return;
        }
        if (! $existing->exists()) {
            $pin = new ProjectPin;
            $pin->forceFill(['user_id' => $user->id, 'project_id' => $project->id])->save();
        }
    }
}
