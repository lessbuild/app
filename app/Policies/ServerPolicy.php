<?php

namespace App\Policies;

use App\Models\Server;
use App\Models\User;

class ServerPolicy
{
    public function view(User $user, Server $server): bool
    {
        return (int) $server->user_id === (int) $user->id;
    }

    public function delete(User $user, Server $server): bool
    {
        return $this->view($user, $server);
    }
}
