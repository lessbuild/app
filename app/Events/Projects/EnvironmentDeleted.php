<?php

declare(strict_types=1);

namespace App\Events\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class EnvironmentDeleted
{
    use Dispatchable;

    public function __construct(
        public Project $project,
        public string $name,
        public User $actor,
    ) {}
}
