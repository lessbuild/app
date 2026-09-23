<?php

namespace App\Modules\Deployer\Actions\Server;

use App\Modules\Deployer\Data\ServerTroubleshootingBrokerLease;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\ServerTroubleshootingSession;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ClaimServerTroubleshootingBrokerLeaseAction
{
    /**
     * Claim an unowned session for one broker process.
     *
     * An expired lease is terminalized rather than replaced so a possibly
     * abandoned remote process cannot overlap a newly opened session.
     */
    public function handle(ServerTroubleshootingSession $session, int $processId): ?ServerTroubleshootingBrokerLease
    {
        if ($processId < 1) {
            return null;
        }

        return DB::transaction(function () use ($processId, $session): ?ServerTroubleshootingBrokerLease {
            $locked = ServerTroubleshootingSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || ! $locked->isActive()) {
                return null;
            }

            $now = now();
            if ($locked->hasExpired($now)) {
                $this->expire($locked, $now);

                return null;
            }

            if ($locked->broker_lease_expires_at?->isFuture()) {
                return null;
            }

            if ($locked->broker_lease_expires_at !== null) {
                $this->fail($locked, $now, ServerTroubleshootingSession::CLOSE_REASON_TRANSPORT);

                return null;
            }

            $locked->load('server');
            if ($locked->server->provisioning_status !== Server::STATUS_ACTIVE) {
                $this->fail($locked, $now, ServerTroubleshootingSession::CLOSE_REASON_SERVER_INACTIVE);

                return null;
            }

            if (! $locked->server->ssh_host_key || ! $locked->server->public_ip || ! $locked->server->ssh_private_key) {
                $this->fail($locked, $now, ServerTroubleshootingSession::CLOSE_REASON_TRANSPORT);

                return null;
            }

            $token = Str::random(64);
            $attempt = ((int) $locked->broker_attempt) + 1;
            $locked->update([
                'broker_lease_hash' => hash('sha256', $token),
                'broker_lease_expires_at' => $now->copy()->addSeconds($this->leaseSeconds()),
                'broker_attempt' => $attempt,
                'broker_process_id' => $processId,
            ]);

            return new ServerTroubleshootingBrokerLease($locked, $token, $attempt, $processId);
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

    private function leaseSeconds(): int
    {
        return max(10, min(300, (int) config('lessbuild.troubleshooting.broker_lease_seconds', 30)));
    }
}
