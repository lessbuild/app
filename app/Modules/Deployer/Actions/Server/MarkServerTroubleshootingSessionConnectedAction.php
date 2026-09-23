<?php

namespace App\Modules\Deployer\Actions\Server;

use App\Modules\Deployer\Data\ServerTroubleshootingBrokerLease;
use App\Modules\Deployer\Models\ServerTroubleshootingSession;
use Illuminate\Support\Facades\DB;

class MarkServerTroubleshootingSessionConnectedAction
{
    /** Mark the exact connecting broker attempt as connected, or reject it as stale. */
    public function handle(ServerTroubleshootingBrokerLease $lease): bool
    {
        return DB::transaction(function () use ($lease): bool {
            $session = $this->lockedLease($lease);
            if (! $session || $session->status !== ServerTroubleshootingSession::STATUS_CONNECTING) {
                return false;
            }

            if ($session->hasExpired()) {
                $this->expire($session);

                return false;
            }

            if (! $session->broker_lease_expires_at?->isFuture()) {
                $this->fail($session);

                return false;
            }

            $session->update([
                'status' => ServerTroubleshootingSession::STATUS_CONNECTED,
                'connected_at' => now(),
            ]);

            return true;
        });
    }

    private function lockedLease(ServerTroubleshootingBrokerLease $lease): ?ServerTroubleshootingSession
    {
        return ServerTroubleshootingSession::query()
            ->whereKey($lease->session->id)
            ->where('broker_lease_hash', hash('sha256', $lease->token))
            ->where('broker_attempt', $lease->attempt)
            ->where('broker_process_id', $lease->processId)
            ->lockForUpdate()
            ->first();
    }

    private function expire(ServerTroubleshootingSession $session): void
    {
        $session->update([
            'status' => ServerTroubleshootingSession::STATUS_EXPIRED,
            'closed_at' => now(),
            'close_reason' => ServerTroubleshootingSession::CLOSE_REASON_EXPIRED,
            'broker_lease_hash' => null,
            'broker_lease_expires_at' => null,
            'broker_process_id' => null,
        ]);
    }

    private function fail(ServerTroubleshootingSession $session): void
    {
        $session->update([
            'status' => ServerTroubleshootingSession::STATUS_FAILED,
            'closed_at' => now(),
            'close_reason' => ServerTroubleshootingSession::CLOSE_REASON_TRANSPORT,
            'broker_lease_hash' => null,
            'broker_lease_expires_at' => null,
            'broker_process_id' => null,
        ]);
    }
}
