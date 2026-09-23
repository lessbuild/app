<?php

namespace App\Modules\Monitor\Policies;

use App\Modules\Monitor\Models\Release;
use App\Modules\Monitor\Models\User;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;

class ReleasePolicy
{
    public function view(User $user, Release $release): Response
    {
        return $release->application === null || $release->application->trashed()
            ? Response::denyAsNotFound()
            : Gate::forUser($user)->inspect('view', $release->application->workspace);
    }
}
