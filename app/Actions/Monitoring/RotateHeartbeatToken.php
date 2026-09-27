<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Exceptions\StateConflict;
use App\Models\Monitor;
use App\Models\User;
use App\Services\Monitoring\MonitorQueue;
use App\Services\Monitoring\MonitorSuspension;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class RotateHeartbeatToken
{
    public function __construct(private readonly MonitorQueue $queue, private readonly MonitorSuspension $suspension) {}

    /** Issue a new heartbeat key (returned once), or revoke the key and pause the monitor. */
    public function handle(User $actor, Monitor $monitor, int $version, bool $revoke = false): ?string
    {
        return DB::transaction(function () use ($actor, $monitor, $version, $revoke): ?string {
            $monitor = $this->queue->lockMonitor($monitor->id);
            abort_unless($monitor !== null && ! $monitor->trashed() && $monitor->type === 'heartbeat', 404);
            Gate::forUser($actor)->authorize('rotateKey', $monitor);
            StateConflict::unlessVersion($monitor->state_version, $version, __('This monitor changed. Refresh before trying again.'));
            if ($revoke) {
                $this->suspension->heartbeat($monitor, revoke: true);

                return null;
            }
            $secret = 'bch_'.Str::random(64);
            $monitor->forceFill(['heartbeat_token_hash' => hash('sha256', $secret),
                'state_version' => $monitor->state_version + 1])->save();

            return $secret;
        }, attempts: 3);
    }
}
