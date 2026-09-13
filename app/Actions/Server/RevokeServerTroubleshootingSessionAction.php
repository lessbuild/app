<?php

namespace App\Actions\Server;

use App\Models\ServerTroubleshootingSession;
use Illuminate\Support\Facades\DB;

class RevokeServerTroubleshootingSessionAction
{
    /** Revoke an active grant after an internal authorization or credential change. */
    public function handle(ServerTroubleshootingSession $session): bool
    {
        return DB::transaction(function () use ($session): bool {
            $locked = ServerTroubleshootingSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || ! $locked->isActive()) {
                return false;
            }

            $locked->update([
                'status' => ServerTroubleshootingSession::STATUS_REVOKED,
                'closed_at' => now(),
                'close_reason' => ServerTroubleshootingSession::CLOSE_REASON_REVOKED,
            ]);

            return true;
        });
    }
}
