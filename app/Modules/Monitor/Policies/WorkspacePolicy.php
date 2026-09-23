<?php

namespace App\Modules\Monitor\Policies;

use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Auth\Access\Response;

class WorkspacePolicy
{
    public function view(User $user, Workspace $workspace): Response
    {
        return $workspace->roleFor($user) !== null ? Response::allow() : Response::denyAsNotFound();
    }

    public function update(User $user, Workspace $workspace): Response
    {
        $role = $workspace->roleFor($user);
        if ($role === null) {
            return Response::denyAsNotFound();
        }

        return in_array($role, ['owner', 'admin'], true) ? Response::allow() : Response::deny();
    }

    public function contribute(User $user, Workspace $workspace): Response
    {
        $role = $workspace->roleFor($user);
        if ($role === null) {
            return Response::denyAsNotFound();
        }

        return in_array($role, ['owner', 'admin', 'member'], true) ? Response::allow() : Response::deny();
    }

    public function billing(User $user, Workspace $workspace): Response
    {
        $role = $workspace->roleFor($user);
        if ($role === null) {
            return Response::denyAsNotFound();
        }

        return $role === 'owner' ? Response::allow() : Response::deny();
    }
}
