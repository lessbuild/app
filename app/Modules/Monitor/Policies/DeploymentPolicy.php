<?php

namespace App\Modules\Monitor\Policies;

use App\Modules\Monitor\Models\Deployment;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class DeploymentPolicy
{
    public function view(User $user, Deployment $deployment): Response
    {
        $environment = $deployment->environment;
        $release = $deployment->release;

        if ($environment === null || $environment->trashed() || $release === null || $release->application_id !== $environment->application_id
            || $environment->application === null || $environment->application->trashed()) {
            return Response::denyAsNotFound();
        }

        return Gate::forUser($user)->inspect('view', $environment->application->workspace);
    }

    public function create(User $user, Environment $environment): Response
    {
        if ($environment->trashed() || $environment->application === null || $environment->application->trashed()) {
            return Response::denyAsNotFound();
        }

        $permission = Gate::forUser($user)->inspect('contribute', $environment->application->workspace);

        return $permission->denied() ? $permission : ($environment->status === 'active'
            ? Response::allow() : Response::deny('Resume this environment before recording a deployment.'));
    }
}
