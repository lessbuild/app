<?php

namespace App\Modules\Monitor\Policies;

use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class EnvironmentPolicy
{
    public function view(User $user, Environment $environment): Response
    {
        return $environment->application === null
            ? Response::denyAsNotFound()
            : Gate::forUser($user)->inspect('view', $environment->application);
    }

    public function update(User $user, Environment $environment): Response
    {
        $permission = $environment->application === null
            ? Response::denyAsNotFound()
            : Gate::forUser($user)->inspect('update', $environment->application);

        return $permission->denied() ? $permission : ($environment->trashed() ? Response::deny('Restore the environment before making changes.') : Response::allow());
    }

    public function delete(User $user, Environment $environment): Response
    {
        return $this->update($user, $environment);
    }

    public function restore(User $user, Environment $environment): Response
    {
        return $environment->application === null
            ? Response::denyAsNotFound()
            : Gate::forUser($user)->inspect('update', $environment->application);
    }
}
