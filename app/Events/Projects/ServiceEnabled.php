<?php

declare(strict_types=1);

namespace App\Events\Projects;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class ServiceEnabled
{
    use Dispatchable;

    /**
     * Create a new ServiceEnabled instance.
     *
     * A service was turned on in a project. Recorded in the project's activity.
     *
     * @param  Project  $project  The project.
     * @param  string  $service  The service's key.
     * @param  User  $actor  Who turned it on.
     */
    public function __construct(
        public Project $project,
        public string $service,
        public User $actor,
    ) {}
}
