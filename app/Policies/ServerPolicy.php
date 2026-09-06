<?php

namespace App\Policies;

use App\Models\Server;
use App\Models\User;

class ServerPolicy
{
    public function view(User $user, Server $server): bool
    {
        return $server->organization
            ? (int) $server->organization_id === (int) $user->current_organization_id
                && $server->organization->permits($user, 'view')
            : (int) $server->user_id === (int) $user->id;
    }

    public function delete(User $user, Server $server): bool
    {
        return $this->view($user, $server)
            && ($server->organization?->permits($user, 'manage') ?? true);
    }

    public function update(User $user, Server $server): bool
    {
        return $this->view($user, $server)
            && ($server->organization?->permits($user, 'deploy') ?? true);
    }
}
