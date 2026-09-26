<?php

namespace App\Modules\Deployer\Actions\Server;

use App\Modules\Deployer\Data\ServerTroubleshootingBrokerLease;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\ServerTroubleshootingSession;
use App\Modules\Deployer\Policies\ServerTroubleshootingSessionPolicy;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class RenewServerTroubleshootingBrokerLeaseAction
{
    public function __construct(
        private readonly ServerTroubleshootingSessionPolicy $sessions,
    ) {}

    /**
     * Revalidate broker ownership and current actor access before renewing only
     * its short lease. User idle time is not extended by this heartbeat.
     */
    public function handle(ServerTroubleshootingBrokerLease $lease): bool
    {
        return DB::connection('deployer')->transaction(function () use ($lease): bool {
            $session = $this->lockedLease($lease);
            if (! $session || $session->statusEnum()?->acceptsActivity() !== true) {
                return false;
            }

            $now = now();
            if ($session->hasExpired($now)) {
                $this->expire($session, $now);

                return false;
            }

            $session->load(['server', 'user']);
            if ($session->server->provisioning_status !== Server::STATUS_ACTIVE) {
                $this->fail($session, $now, ServerTroubleshootingSession::CLOSE_REASON_SERVER_INACTIVE);

                return false;
            }

            if (! $session->user || ! $this->sessions->connect($session->user, $session)) {
                $this->revoke($session, $now);

                return false;
            }

            if (! $session->broker_lease_expires_at?->isFuture()) {
                $this->fail($session, $now, ServerTroubleshootingSession::CLOSE_REASON_TRANSPORT);

                return false;
            }

            $leaseUntil = $now->copy()->addSeconds($this->leaseSeconds());
            if ($leaseUntil->greaterThan($session->expires_at)) {
                $leaseUntil = $session->expires_at->copy();
            }
            if ($leaseUntil->greaterThan($session->idle_expires_at)) {
                $leaseUntil = $session->idle_expires_at->copy();
            }
            if (! $leaseUntil->isFuture()) {
                $this->expire($session, $now);

                return false;
            }

            $session->update(['broker_lease_expires_at' => $leaseUntil]);

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

    private function fail(ServerTroubleshootingSession $session, CarbonInterface $now, string $reason): void
    {
        $session->update([
            'status' => ServerTroubleshootingSession::STATUS_FAILED,
            'closed_at' => $now,
            'close_reason' => $reason,
            'broker_lease_hash' => null,
            'broker_lease_expires_at' => null,
            'broker_process_id' => null,
        ]);
    }

    private function revoke(ServerTroubleshootingSession $session, CarbonInterface $now): void
    {
        $session->update([
            'status' => ServerTroubleshootingSession::STATUS_REVOKED,
            'closed_at' => $now,
            'close_reason' => ServerTroubleshootingSession::CLOSE_REASON_REVOKED,
            'broker_lease_hash' => null,
            'broker_lease_expires_at' => null,
            'broker_process_id' => null,
        ]);
    }

    private function leaseSeconds(): int
    {
        return max(10, min(300, (int) config('lessbuild.troubleshooting.broker_lease_seconds', 30)));
    }
}
