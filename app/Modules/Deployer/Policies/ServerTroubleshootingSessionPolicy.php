<?php

namespace App\Modules\Deployer\Policies;

use App\Modules\Deployer\Models\ServerTroubleshootingSession;
use App\Modules\Deployer\Models\User;

class ServerTroubleshootingSessionPolicy
{
    public function __construct(private readonly ServerPolicy $servers) {}

    /** Allow workspace viewers to inspect metadata for their own session. */
    public function view(User $user, ServerTroubleshootingSession $session): bool
    {
        return (int) $session->user_id === (int) $user->id
            && $this->servers->view($user, $session->server);
    }

    /** Allow only the session actor to present its connect grant. */
    public function connect(User $user, ServerTroubleshootingSession $session): bool
    {
        return $this->view($user, $session)
            && $this->servers->connect($user, $session->server);
    }

    /** Require a stronger workspace operation ability before shell input is accepted. */
    public function execute(User $user, ServerTroubleshootingSession $session): bool
    {
        return $this->connect($user, $session)
            && $this->servers->execute($user, $session->server);
    }

    /** Allow the session actor to close its own grant. */
    public function close(User $user, ServerTroubleshootingSession $session): bool
    {
        return $this->connect($user, $session);
    }
}
