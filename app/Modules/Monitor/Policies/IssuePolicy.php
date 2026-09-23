<?php

namespace App\Modules\Monitor\Policies;

use App\Modules\Monitor\Models\Issue;
use App\Modules\Monitor\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class IssuePolicy
{
    public function view(User $user, Issue $issue): Response
    {
        return $issue->application === null || $issue->application->trashed()
            ? Response::denyAsNotFound()
            : Gate::forUser($user)->inspect('view', $issue->application->workspace);
    }

    public function update(User $user, Issue $issue): Response
    {
        return $issue->application === null || $issue->application->trashed()
            ? Response::denyAsNotFound()
            : Gate::forUser($user)->inspect('contribute', $issue->application->workspace);
    }
}
