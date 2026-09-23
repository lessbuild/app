<?php

namespace App\Modules\Deployer\Actions\Server;

use App\Modules\Deployer\Data\ServerTroubleshootingSessionGrant;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\ServerTroubleshootingSession;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Policies\ServerPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OpenServerTroubleshootingSessionAction
{
    public function __construct(private readonly ServerPolicy $servers) {}

    /**
     * Issue a short-lived, non-executing session grant for an active server.
     *
     * No SSH connection, PTY, job or remote process is created here. The
     * returned plaintext token exists only at this application boundary.
     *
     * @throws AuthorizationException If the actor cannot view the server.
     * @throws ValidationException If the server or session capacity is ineligible.
     */
    public function handle(Server $server, User $user): ServerTroubleshootingSessionGrant
    {
        return DB::transaction(function () use ($server, $user): ServerTroubleshootingSessionGrant {
            $locked = Server::query()->lockForUpdate()->findOrFail($server->id);
            if (! $this->servers->connect($user, $locked)) {
                throw new AuthorizationException;
            }

            if ($locked->provisioning_status !== Server::STATUS_ACTIVE) {
                throw ValidationException::withMessages([
                    'session' => __('Troubleshooting sessions are available only for active servers.'),
                ]);
            }

            if (! $locked->ssh_host_key) {
                throw ValidationException::withMessages([
                    'session' => __('A pinned SSH host identity is required before a troubleshooting session can open.'),
                ]);
            }

            if (! $locked->public_ip || ! $locked->ssh_private_key) {
                throw ValidationException::withMessages([
                    'session' => __('The server does not have the connection details required for a troubleshooting session.'),
                ]);
            }

            $now = now();
            $active = $locked->troubleshootingSessions()
                ->whereIn('status', ServerTroubleshootingSession::ACTIVE_STATUSES)
                ->lockForUpdate()
                ->get();

            foreach ($active as $existing) {
                if ($existing->brokerLeaseExpired($now)) {
                    $existing->update([
                        'status' => ServerTroubleshootingSession::STATUS_FAILED,
                        'closed_at' => $now,
                        'close_reason' => ServerTroubleshootingSession::CLOSE_REASON_TRANSPORT,
                        'broker_lease_hash' => null,
                        'broker_lease_expires_at' => null,
                        'broker_process_id' => null,
                    ]);
                } elseif ($existing->hasExpired($now)) {
                    $existing->update([
                        'status' => ServerTroubleshootingSession::STATUS_EXPIRED,
                        'closed_at' => $now,
                        'close_reason' => ServerTroubleshootingSession::CLOSE_REASON_EXPIRED,
                        'broker_lease_hash' => null,
                        'broker_lease_expires_at' => null,
                        'broker_process_id' => null,
                    ]);
                }
            }

            $active = $active->reject(fn (ServerTroubleshootingSession $existing): bool => $existing->hasExpired($now));
            if ($active->contains(fn (ServerTroubleshootingSession $existing): bool => (int) $existing->user_id === (int) $user->id)) {
                throw ValidationException::withMessages([
                    'session' => __('You already have an active troubleshooting session for this server.'),
                ]);
            }

            $maximum = max(1, min(10, (int) config('lessbuild.troubleshooting.max_sessions_per_server', 1)));
            if ($active->count() >= $maximum) {
                throw ValidationException::withMessages([
                    'session' => __('This server already has the maximum number of active troubleshooting sessions.'),
                ]);
            }

            $token = Str::random(64);
            $expiresAt = $now->copy()->addSeconds($this->ttlSeconds());
            $idleExpiresAt = $now->copy()->addSeconds(min($this->ttlSeconds(), $this->idleSeconds()));
            $session = $locked->troubleshootingSessions()->create([
                'user_id' => $user->id,
                'public_id' => (string) Str::uuid(),
                'status' => ServerTroubleshootingSession::STATUS_CONNECTING,
                'grant_hash' => hash('sha256', $token),
                'expires_at' => $expiresAt,
                'idle_expires_at' => $idleExpiresAt,
                'last_seen_at' => $now,
            ]);

            return new ServerTroubleshootingSessionGrant($session, $token);
        });
    }

    private function ttlSeconds(): int
    {
        return max(60, min(3600, (int) config('lessbuild.troubleshooting.session_ttl_seconds', 900)));
    }

    private function idleSeconds(): int
    {
        return max(30, min(1800, (int) config('lessbuild.troubleshooting.session_idle_seconds', 300)));
    }
}
