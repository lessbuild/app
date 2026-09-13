<?php

namespace App\Actions\Server;

use App\Models\ServerTroubleshootingSession;
use Illuminate\Support\Facades\DB;

class ExpireServerTroubleshootingSessionsAction
{
    /** Expire a bounded batch of idle or absolute-deadline sessions. */
    public function handle(int $limit = 100): int
    {
        $limit = max(1, min(5000, $limit));
        $now = now();

        return DB::transaction(function () use ($limit, $now): int {
            $sessions = ServerTroubleshootingSession::query()
                ->whereIn('status', ServerTroubleshootingSession::ACTIVE_STATUSES)
                ->where(function ($query) use ($now): void {
                    $query
                        ->where('expires_at', '<=', $now)
                        ->orWhere('idle_expires_at', '<=', $now);
                })
                ->orderBy('id')
                ->limit($limit)
                ->lockForUpdate()
                ->get();

            foreach ($sessions as $session) {
                $session->update([
                    'status' => ServerTroubleshootingSession::STATUS_EXPIRED,
                    'closed_at' => $now,
                    'close_reason' => ServerTroubleshootingSession::CLOSE_REASON_EXPIRED,
                ]);
            }

            return $sessions->count();
        });
    }
}
