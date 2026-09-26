<?php

namespace App\Modules\Deployer\Actions\Server;

use App\Modules\Deployer\Data\ServerTroubleshootingFrameData;
use App\Modules\Deployer\Data\ServerTroubleshootingTerminalSize;
use App\Modules\Deployer\Models\ServerTroubleshootingSession;
use App\Modules\Deployer\Models\User;

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
