<?php

namespace App\Modules\Monitor\Policies;

use App\Modules\Monitor\Models\Application;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Services\Core\MonitorProjectAccess;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class ApplicationPolicy
{
    public function __construct(private readonly MonitorProjectAccess $projects) {}

    public function view(User $user, Application $application): Response
    {
        return $this->inspect($user, $application, 'view');
    }

    public function update(User $user, Application $application): Response
    {
        $permission = $this->inspect($user, $application, 'update');

        return $permission->denied() ? $permission : ($application->trashed() ? Response::deny('Restore the application before making changes.') : Response::allow());
    }

    public function delete(User $user, Application $application): Response
    {
        return $this->update($user, $application);
    }

    public function restore(User $user, Application $application): Response
    {
        return $this->inspect($user, $application, 'update');
    }

    public function contribute(User $user, Application $application): Response
    {
        return $this->inspect($user, $application, 'contribute');
    }

    private function inspect(User $user, Application $application, string $ability): Response
    {
        if (! $this->projects->application($user, $application)) {
            return Response::denyAsNotFound();
        }
        $permission = Gate::forUser($user)->inspect($ability, $application->workspace);
        if ($permission->denied() || $ability !== 'update') {
            return $permission;
        }

        // Application-wide changes can affect every environment, including archived ones.
        $all = $application->environments()->withTrashed()->count();
        $visible = $application->environments()->withTrashed()->visibleTo($user, $application->workspace)->count();

        return $all === $visible ? $permission : Response::denyAsNotFound();
    }
}
