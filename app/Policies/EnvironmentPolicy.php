<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Environment;
use App\Models\User;

/** An environment's Deploy settings (controls, runtime, variables, processes, resources): members and above with Deploy access. */
final class EnvironmentPolicy
{
    public function configureDeploy(User $user, Environment $environment): bool
    {
        return $user->can('manageService', [$environment->project, 'deploy']);
    }
}
