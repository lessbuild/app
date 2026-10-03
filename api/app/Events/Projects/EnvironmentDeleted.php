<?php

declare(strict_types=1);

namespace App\Events\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class EnvironmentDeleted
{
    use Dispatchable;

    /**
     * Create a new EnvironmentDeleted instance.
     *
     * An environment was deleted. It's gone when this fires, so the name is carried separately.
     *
     * @param  Project  $project  The project it belonged to.
     * @param  string  $name  The deleted environment's name.
     * @param  User  $actor  Who deleted it.
     */
    public function __construct(
        public Project $project,
        public string $name,
        public User $actor,
    ) {}
}
