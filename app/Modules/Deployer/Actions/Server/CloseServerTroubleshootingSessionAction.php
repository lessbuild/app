<?php

namespace App\Modules\Deployer\Actions\Server;

use App\Modules\Deployer\Models\ServerTroubleshootingSession;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Policies\ServerTroubleshootingSessionPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class CloseServerTroubleshootingSessionAction
{
    public function __construct(private readonly ServerTroubleshootingSessionPolicy $sessions) {}

    /** Close a still-active grant without contacting a remote host. */
    public function handle(ServerTroubleshootingSession $session, User $user, string $token): bool
    {
        return DB::transaction(function () use ($session, $token, $user): bool {
            $locked = ServerTroubleshootingSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked->load('server');

            if (! $locked->matchesGrant($token) || ! $this->sessions->close($user, $locked)) {
                throw new AuthorizationException;
            }

            if (! $locked->isActive()) {
                return false;
            }

            $now = now();
            if ($locked->hasExpired($now)) {
                $locked->update([
                    'status' => ServerTroubleshootingSession::STATUS_EXPIRED,
                    'closed_at' => $now,
                    'close_reason' => ServerTroubleshootingSession::CLOSE_REASON_EXPIRED,
                    'broker_lease_hash' => null,
                    'broker_lease_expires_at' => null,
                    'broker_process_id' => null,
                ]);

                return false;
            }

            $locked->update([
                'status' => ServerTroubleshootingSession::STATUS_CLOSED,
                'closed_at' => $now,
                'close_reason' => ServerTroubleshootingSession::CLOSE_REASON_USER,
                'broker_lease_hash' => null,
                'broker_lease_expires_at' => null,
                'broker_process_id' => null,
            ]);

            return true;
        });
    }
}
