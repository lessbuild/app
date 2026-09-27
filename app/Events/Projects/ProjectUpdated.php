<?php

declare(strict_types=1);

namespace App\Events\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class ProjectUpdated
{
    use Dispatchable;

    /**
     * A project's name or description changed. Recorded in the project's activity.
     *
     * @param  Project  $project  The project, already carrying the change.
     * @param  string  $previousName  Its name before, so a rename can be described.
     * @param  User  $actor  Who changed it.
     */
    public function __construct(
        public Project $project,
        public string $previousName,
        public User $actor,
    ) {}
}
