<?php

namespace App\Actions\Server;

use App\Data\ServerTroubleshootingBrokerLease;
use App\Models\ServerTroubleshootingSession;
use Illuminate\Support\Facades\DB;

class ReleaseServerTroubleshootingBrokerLeaseAction
{
    /** Release only the exact process owner; optionally make a safe terminal failure. */
    public function handle(ServerTroubleshootingBrokerLease $lease, bool $failed = false): bool
    {
        return DB::transaction(function () use ($failed, $lease): bool {
            $session = ServerTroubleshootingSession::query()
                ->whereKey($lease->session->id)
                ->where('broker_lease_hash', hash('sha256', $lease->token))
                ->where('broker_attempt', $lease->attempt)
                ->where('broker_process_id', $lease->processId)
                ->lockForUpdate()
                ->first();

            if (! $session) {
                return false;
            }

            if ($failed && $session->isActive()) {
                $session->update([
                    'status' => ServerTroubleshootingSession::STATUS_FAILED,
                    'closed_at' => now(),
                    'close_reason' => ServerTroubleshootingSession::CLOSE_REASON_TRANSPORT,
                ]);
            }

            $session->update([
                'broker_lease_hash' => null,
                'broker_lease_expires_at' => null,
                'broker_process_id' => null,
            ]);

            return true;
        });
    }
}
