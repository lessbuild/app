<?php

namespace App\Modules\Monitor\Policies;

use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class MonitorPolicy
{
    public function create(User $user, Workspace $workspace): Response
    {
        return Gate::forUser($user)->inspect('update', $workspace);
    }

    public function view(User $user, Monitor $monitor): Response
    {
        return $this->inspect($user, $monitor, 'view');
    }

    public function update(User $user, Monitor $monitor): Response
    {
        return $monitor->trashed() ? Response::denyAsNotFound() : $this->inspect($user, $monitor, 'update');
    }

    public function delete(User $user, Monitor $monitor): Response
    {
        return $this->update($user, $monitor);
    }

    private function inspect(User $user, Monitor $monitor, string $ability): Response
    {
        $environment = $monitor->environment;
        $application = $environment?->application;

        return $application === null || $environment->trashed() || $application->trashed()
            ? Response::denyAsNotFound()
            : Gate::forUser($user)->inspect($ability, $monitor->environment);
    }
}
