<?php

declare(strict_types=1);

namespace App\Events\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class ProjectCreated
{
    use Dispatchable;

    /**
     * Create a new ProjectCreated instance.
     *
     * A project was created with its production environment. Recorded in the audit log.
     *
     * @param  Project  $project  The new project.
     * @param  User  $actor  Who created it.
     */
    public function __construct(
        public Project $project,
        public User $actor,
    ) {}
}
