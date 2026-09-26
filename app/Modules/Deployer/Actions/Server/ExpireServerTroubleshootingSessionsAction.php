<?php

namespace App\Modules\Deployer\Actions\Server;

use App\Modules\Deployer\Models\ServerTroubleshootingSession;
use Illuminate\Support\Facades\DB;

class ExpireServerTroubleshootingSessionsAction
{
    /** Expire a bounded batch of idle or absolute-deadline sessions. */
    public function handle(int $limit = 100): int
    {
        $limit = max(1, min(5000, $limit));
        $now = now();

        return DB::connection('deployer')->transaction(function () use ($limit, $now): int {
            $sessions = ServerTroubleshootingSession::query()
                ->whereIn('status', ServerTroubleshootingSession::ACTIVE_STATUSES)
                ->where(function ($query) use ($now): void {
                    $query
                        ->where('expires_at', '<=', $now)
                        ->orWhere('idle_expires_at', '<=', $now)
                        ->orWhere(function ($query) use ($now): void {
                            $query
                                ->whereNotNull('broker_lease_expires_at')
                                ->where('broker_lease_expires_at', '<=', $now);
                        });
                })
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            foreach ($sessions as $session) {
                $brokerExpired = $session->brokerLeaseExpired($now)
                    && ! $session->hasExpired($now);
                $session->update([
                    'status' => $brokerExpired
                        ? ServerTroubleshootingSession::STATUS_FAILED
                        : ServerTroubleshootingSession::STATUS_EXPIRED,
                    'closed_at' => $now,
                    'close_reason' => $brokerExpired
                        ? ServerTroubleshootingSession::CLOSE_REASON_TRANSPORT
                        : ServerTroubleshootingSession::CLOSE_REASON_EXPIRED,
                    'broker_lease_hash' => null,
                    'broker_lease_expires_at' => null,
                    'broker_process_id' => null,
                ]);
            }

            return $sessions->count();
        });
    }
}
