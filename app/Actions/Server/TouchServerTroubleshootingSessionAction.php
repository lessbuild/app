<?php

namespace App\Actions\Server;

use App\Models\Server;
use App\Models\ServerTroubleshootingSession;
use App\Models\User;
use App\Policies\ServerTroubleshootingSessionPolicy;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class TouchServerTroubleshootingSessionAction
{
    public function __construct(private readonly ServerTroubleshootingSessionPolicy $sessions) {}

    /**
     * Revalidate a grant and extend only its idle deadline.
     *
     * @return bool True when the grant remains usable; false after a terminal
     *              or expired/server-inactive transition.
     *
     * @throws AuthorizationException If the grant or current actor access is invalid.
     */
    public function handle(ServerTroubleshootingSession $session, User $user, string $token): bool
    {
        return DB::transaction(function () use ($session, $token, $user): bool {
            $locked = ServerTroubleshootingSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked->load('server');

            if (! $locked->matchesGrant($token) || ! $this->sessions->connect($user, $locked)) {
                throw new AuthorizationException;
            }

            $status = $locked->statusEnum();
            if ($status?->acceptsActivity() !== true) {
                return false;
            }

            $now = now();
            if ($locked->hasExpired($now)) {
                $this->expire($locked, $now);

                return false;
            }

            if ($locked->brokerLeaseExpired($now)) {
                $locked->update([
                    'status' => ServerTroubleshootingSession::STATUS_FAILED,
                    'closed_at' => $now,
                    'close_reason' => ServerTroubleshootingSession::CLOSE_REASON_TRANSPORT,
                    'broker_lease_hash' => null,
                    'broker_lease_expires_at' => null,
                    'broker_process_id' => null,
                ]);

                return false;
            }

            if ($locked->server->provisioning_status !== Server::STATUS_ACTIVE) {
                $locked->update([
                    'status' => ServerTroubleshootingSession::STATUS_FAILED,
                    'closed_at' => $now,
                    'close_reason' => ServerTroubleshootingSession::CLOSE_REASON_SERVER_INACTIVE,
                    'broker_lease_hash' => null,
                    'broker_lease_expires_at' => null,
                    'broker_process_id' => null,
                ]);

                return false;
            }

            $idle = $now->copy()->addSeconds($this->idleSeconds());
            $locked->update([
                'last_seen_at' => $now,
                'idle_expires_at' => $idle->lessThan($locked->expires_at) ? $idle : $locked->expires_at,
            ]);

            return true;
        });
    }

    private function expire(ServerTroubleshootingSession $session, CarbonInterface $now): void
    {
        $session->update([
            'status' => ServerTroubleshootingSession::STATUS_EXPIRED,
            'closed_at' => $now,
            'close_reason' => ServerTroubleshootingSession::CLOSE_REASON_EXPIRED,
            'broker_lease_hash' => null,
            'broker_lease_expires_at' => null,
            'broker_process_id' => null,
        ]);
    }

    private function idleSeconds(): int
    {
        return max(30, min(1800, (int) config('lessbuild.troubleshooting.session_idle_seconds', 300)));
    }
}
