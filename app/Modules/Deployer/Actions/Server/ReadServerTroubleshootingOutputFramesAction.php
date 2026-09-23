<?php

namespace App\Modules\Deployer\Actions\Server;

use App\Modules\Deployer\Data\ServerTroubleshootingFrameData;
use App\Modules\Deployer\Models\ServerTroubleshootingSession;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Policies\ServerTroubleshootingSessionPolicy;
use App\Modules\Deployer\Services\ServerTroubleshootingFrameStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

class ReadServerTroubleshootingOutputFramesAction
{
    public function __construct(
        private readonly ServerTroubleshootingSessionPolicy $sessions,
        private readonly ServerTroubleshootingFrameStore $frames,
    ) {}

    /** Read a bounded, ordered output window without exposing the frame model. */
    /**
     * @return list<ServerTroubleshootingFrameData>
     */
    public function handle(
        ServerTroubleshootingSession $session,
        User $user,
        string $token,
        int $after = 0,
        int $limit = 50,
    ): array {
        $after = max(0, $after);
        $limit = max(1, min(100, $limit));

        return DB::transaction(function () use ($after, $limit, $session, $token, $user): array {
            $locked = ServerTroubleshootingSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked->load('server');

            if (! $locked->matchesGrant($token) || ! $this->sessions->connect($user, $locked)) {
                throw new AuthorizationException;
            }

            if ($locked->statusEnum()?->acceptsActivity() !== true) {
                return [];
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

                return [];
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

                return [];
            }

            $idle = $now->copy()->addSeconds($this->idleSeconds());
            $locked->update([
                'last_seen_at' => $now,
                'idle_expires_at' => $idle->lessThan($locked->expires_at) ? $idle : $locked->expires_at,
            ]);

            return $this->frames->readOutputLocked($locked, $after, $limit);
        });
    }

    private function idleSeconds(): int
    {
        return max(30, min(1800, (int) config('lessbuild.troubleshooting.session_idle_seconds', 300)));
    }
}
