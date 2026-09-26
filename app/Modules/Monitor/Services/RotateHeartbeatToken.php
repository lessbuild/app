<?php

namespace App\Modules\Monitor\Services;

use App\Modules\Monitor\Models\Monitor;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class RotateHeartbeatToken
{
    public function __construct(private readonly MonitorQueue $queue, private readonly SuspendHeartbeats $suspension) {}

    public function change(Workspace $workspace, User $actor, Monitor $monitor, int $version, bool $revoke = false): ?string
    {
        return DB::connection('monitor')->transaction(function () use ($workspace, $actor, $monitor, $version, $revoke): ?string {
            $workspace = Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
            abort_unless(Monitor::forWorkspace($workspace)->whereKey($monitor->id)->exists(), 404);
            Gate::forUser($actor)->authorize('update', $workspace);
            abort_unless($actor->hasVerifiedEmail(), 403);
            $monitor = $this->queue->lockMonitor($monitor->id);
            Gate::forUser($actor)->authorize('update', $monitor);
            abort_unless($monitor->type === 'heartbeat', 404);
            abort_unless($monitor->state_version === $version, 409, 'This monitor changed. Refresh before trying again.');
            if ($revoke) {
                $this->suspension->monitor($monitor, revoke: true);

                return null;
            }
            $secret = 'bch_'.Str::random(64);
            $monitor->forceFill(['heartbeat_token_hash' => hash('sha256', $secret),
                'state_version' => $monitor->state_version + 1])->save();

            return $secret;
        }, attempts: 3);
    }
}
