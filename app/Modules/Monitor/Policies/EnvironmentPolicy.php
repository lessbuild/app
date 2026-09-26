<?php

namespace App\Modules\Monitor\Policies;

use App\Core\Enums\ProjectResourceAccessPurpose;
use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Services\Core\MonitorProjectAccess;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class EnvironmentPolicy
{
    public function __construct(private readonly MonitorProjectAccess $projects) {}

    public function view(User $user, Environment $environment): Response
    {
        return $environment->application === null || ! $this->projects->environment($user, $environment)
            ? Response::denyAsNotFound()
            : Gate::forUser($user)->inspect('view', $environment->application);
    }

    public function update(User $user, Environment $environment): Response
    {
        $permission = $environment->application === null || ! $this->projects->environment($user, $environment)
            ? Response::denyAsNotFound()
            : Gate::forUser($user)->inspect('update', $environment->application->workspace);

        return $permission->denied() ? $permission : ($environment->trashed() ? Response::deny('Restore the environment before making changes.') : Response::allow());
    }

    public function delete(User $user, Environment $environment): Response
    {
        return $this->update($user, $environment);
    }

    public function viewRetained(User $user, Environment $environment): Response
    {
        $application = $environment->application()->withTrashed()->first();

        return $application === null || ! $this->projects->environment($user, $environment, ProjectResourceAccessPurpose::RetainedRead)
            ? Response::denyAsNotFound()
            : Gate::forUser($user)->inspect('view', $application->workspace);
    }

    public function restore(User $user, Environment $environment): Response
    {
        return $environment->application === null || ! $this->projects->environment($user, $environment, ProjectResourceAccessPurpose::Restoration)
            ? Response::denyAsNotFound()
            : Gate::forUser($user)->inspect('update', $environment->application->workspace);
    }

    public function contribute(User $user, Environment $environment): Response
    {
        return $environment->application === null || ! $this->projects->environment($user, $environment)
            ? Response::denyAsNotFound()
            : Gate::forUser($user)->inspect('contribute', $environment->application);
    }
}
