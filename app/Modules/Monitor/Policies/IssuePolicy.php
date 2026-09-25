<?php

namespace App\Modules\Monitor\Policies;

use App\Modules\Monitor\Models\Environment;
use App\Modules\Monitor\Models\Issue;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Services\Core\MonitorProjectAccess;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class IssuePolicy
{
    public function __construct(private readonly MonitorProjectAccess $projects) {}

    public function view(User $user, Issue $issue): Response
    {
        return $issue->application === null || $issue->application->trashed() || ! $this->environmentAllowed($user, $issue)
            ? Response::denyAsNotFound()
            : Gate::forUser($user)->inspect('view', $issue->application);
    }

    public function update(User $user, Issue $issue): Response
    {
        return $issue->application === null || $issue->application->trashed() || ! $this->environmentAllowed($user, $issue)
            ? Response::denyAsNotFound()
            : Gate::forUser($user)->inspect('contribute', $issue->application);
    }

    private function environmentAllowed(User $user, Issue $issue): bool
    {
        if ($issue->environment_id === null) {
            return true;
        }
        $environment = Environment::withTrashed()->find($issue->environment_id);

        return $environment !== null && $environment->application_id === $issue->application_id
            && $this->projects->environment($user, $environment);
    }
}
