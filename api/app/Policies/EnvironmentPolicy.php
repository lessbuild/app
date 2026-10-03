<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Environment;
use App\Models\User;
use App\Policies\Concerns\ChecksAccountRole;

/**
 * An environment's Deploy settings (controls, runtime, variables, processes, resources): members and above with Deploy
 * access, and for protected environments only those allowed to deploy them.
 */
final class EnvironmentPolicy
{
    use ChecksAccountRole;

    /**
     * Determine whether the user can change an environment's deploy settings, variables, processes and resources:
     * people who manage Deploy in its project and, when it's protected, may deploy protected environments.
     *
     * @param  User  $user
     * @param  Environment  $environment
     * @return bool
     */
    public function configureDeploy(User $user, Environment $environment): bool
    {
        return $user->can('manageService', [$environment->project, 'deploy']) && $this->mayTouchEnvironment($user, $environment);
    }
}
