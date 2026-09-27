<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ServerTerminalSession;
use App\Models\User;

/** A terminal is only ever used by the person who opened it, and only while they may still run commands on the server. */
final class ServerTerminalSessionPolicy
{
    public function use(User $user, ServerTerminalSession $terminal): bool
    {
        return $terminal->user_id === $user->id && $user->can('runCommands', $terminal->server);
    }
}
