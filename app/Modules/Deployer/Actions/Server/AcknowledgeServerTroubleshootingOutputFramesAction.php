<?php

namespace App\Modules\Deployer\Actions\Server;

use App\Modules\Deployer\Models\ServerTroubleshootingSession;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Policies\ServerTroubleshootingSessionPolicy;
use App\Modules\Deployer\Services\ServerTroubleshootingFrameStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class AcknowledgeServerTroubleshootingOutputFramesAction
{
    public function __construct(
        private readonly ServerTroubleshootingSessionPolicy $sessions,
        private readonly ServerTroubleshootingFrameStore $frames,
    ) {}

    /** Acknowledge a bounded output sequence after rechecking session access. */
    public function handle(ServerTroubleshootingSession $session, User $user, string $token, int $through): int
    {
        $through = max(0, $through);

        return DB::connection('deployer')->transaction(function () use ($session, $through, $token, $user): int {
            $locked = ServerTroubleshootingSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked->load('server');

            if (! $locked->matchesGrant($token) || ! $this->sessions->connect($user, $locked)) {
                throw new AuthorizationException;
            }

            if (! $locked->isActive()) {
                return 0;
            }

            return $this->frames->acknowledgeOutputLocked($locked, $through);
        });
    }
}
