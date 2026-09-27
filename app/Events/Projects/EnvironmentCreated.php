<?php

declare(strict_types=1);

namespace App\Events\Projects;

use App\Models\Environment;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;

final readonly class EnvironmentCreated
{
    use Dispatchable;

    /**
     * An environment was added to a project. Recorded in the project's activity.
     *
     * @param  Environment  $environment  The new environment.
     * @param  User  $actor  Who created it.
     */
    public function __construct(
        public Environment $environment,
        public User $actor,
    ) {}
}
