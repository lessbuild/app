<?php

namespace App\Actions\Server;

use App\Data\ServerTroubleshootingFrameData;
use App\Data\ServerTroubleshootingTerminalSize;
use App\Models\ServerTroubleshootingSession;
use App\Models\User;

class QueueServerTroubleshootingResizeFrameAction
{
    public function __construct(
        private readonly QueueServerTroubleshootingInputFrameAction $input,
    ) {}

    /**
     * Queue a validated terminal-size control frame through the normal input
     * path so it retains the same authorization, locking and backpressure rules.
     */
    public function handle(
        ServerTroubleshootingSession $session,
        User $user,
        string $token,
        ServerTroubleshootingTerminalSize $size,
    ): ServerTroubleshootingFrameData {
        return $this->input->handle($session, $user, $token, $size->controlFrame());
    }
}
