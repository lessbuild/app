<?php

namespace App\Modules\Monitor\Policies;

use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class ApplicationPolicy
{
    public function view(User $user, Application $application): Response
    {
        return Gate::forUser($user)->inspect('view', $application->workspace);
    }

    public function update(User $user, Application $application): Response
    {
        $permission = Gate::forUser($user)->inspect('update', $application->workspace);

        return $permission->denied() ? $permission : ($application->trashed() ? Response::deny('Restore the application before making changes.') : Response::allow());
    }

    public function delete(User $user, Application $application): Response
    {
        return $this->update($user, $application);
    }

    public function restore(User $user, Application $application): Response
    {
        return Gate::forUser($user)->inspect('update', $application->workspace);
    }
}
