<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Environment;
use App\Models\User;

/** An environment's Deploy settings (controls, runtime, variables, processes, resources): members and above with Deploy access. */
final class EnvironmentPolicy
{
    /**
     * Changing an environment's deploy settings, variables, processes and resources: people who manage Deploy in its
     * project.
     *
     * @param  User  $user
     * @param  Environment  $environment
     * @return bool
     */
    public function configureDeploy(User $user, Environment $environment): bool
    {
        return $user->can('manageService', [$environment->project, 'deploy']);
    }
}
