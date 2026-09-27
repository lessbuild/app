<?php

declare(strict_types=1);

namespace App\Events\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class ProjectCreated
{
    use Dispatchable;

    public function __construct(
        public Project $project,
        public User $actor,
    ) {}
}
