<?php

namespace App\Modules\Deployer\Actions\Server;

use App\Modules\Deployer\Data\ServerTroubleshootingFrameData;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\ServerTroubleshootingSession;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Policies\ServerTroubleshootingSessionPolicy;
use App\Modules\Deployer\Services\ServerTroubleshootingFrameStore;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QueueServerTroubleshootingInputFrameAction
{
    public function __construct(
        private readonly ServerTroubleshootingSessionPolicy $sessions,
        private readonly ServerTroubleshootingFrameStore $frames,
    ) {}

    /** Queue one bounded shell-input frame after revalidating execute access. */
    public function handle(
        ServerTroubleshootingSession $session,
        User $user,
        string $token,
        string $payload,
    ): ServerTroubleshootingFrameData {
        $bytes = strlen($payload);

        return DB::connection('deployer')->transaction(function () use ($bytes, $payload, $session, $token, $user): ServerTroubleshootingFrameData {
            $locked = ServerTroubleshootingSession::query()
                ->whereKey($session->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked->load('server');

            if (! $locked->matchesGrant($token) || ! $this->sessions->execute($user, $locked)) {
                throw new AuthorizationException;
            }

            $maximumBytes = max(1, (int) config('lessbuild.troubleshooting.input_max_bytes', 8192));
            if ($bytes === 0 || $bytes > $maximumBytes) {
                throw ValidationException::withMessages([
                    'input' => __('The troubleshooting input frame is empty or too large.'),
                ]);
            }

            if ($locked->statusEnum()?->acceptsActivity() !== true) {
                throw ValidationException::withMessages([
                    'session' => __('The troubleshooting session is no longer accepting input.'),
                ]);
            }

            $now = now();
            if ($locked->hasExpired($now)) {
                $this->expire($locked, $now);

                throw ValidationException::withMessages([
                    'session' => __('The troubleshooting session has expired.'),
                ]);
            }

            if ($locked->brokerLeaseExpired($now)) {
                $this->fail($locked, $now);

                throw ValidationException::withMessages([
                    'session' => __('The troubleshooting connection is no longer available.'),
                ]);
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

                throw ValidationException::withMessages([
                    'session' => __('The server is no longer active for troubleshooting.'),
                ]);
            }

            $pending = $locked->frames()
                ->where('direction', 'input')
                ->whereNull('sent_at');
            $pendingBytes = (int) $pending->sum('payload_bytes');
            $maximumFrames = max(1, min(10000, (int) config('lessbuild.troubleshooting.max_pending_input_frames', 100)));
            $maximumPendingBytes = max(1, min(100000000, (int) config('lessbuild.troubleshooting.max_pending_input_bytes', 65536)));

            if ((int) $pending->count() >= $maximumFrames || $pendingBytes + $bytes > $maximumPendingBytes) {
                throw ValidationException::withMessages([
                    'input' => __('The troubleshooting input buffer is full; wait for the session to send pending input.'),
                ]);
            }

            return $this->frames->appendInputLocked($locked, $payload);
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

    private function fail(ServerTroubleshootingSession $session, CarbonInterface $now): void
    {
        $session->update([
            'status' => ServerTroubleshootingSession::STATUS_FAILED,
            'closed_at' => $now,
            'close_reason' => ServerTroubleshootingSession::CLOSE_REASON_TRANSPORT,
            'broker_lease_hash' => null,
            'broker_lease_expires_at' => null,
            'broker_process_id' => null,
        ]);
    }
}
