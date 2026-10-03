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

final class RotateQueueToken
{
    /**
     * Create a new RotateQueueToken instance.
     *
     * Replaces a queue monitor's key.
     *
     * @param  MonitorQueue  $queue  Locks the monitor.
     * @param  MonitorSuspension  $suspension  Pauses the monitor, so reports signed with the old key can't change its
     *                                         health.
     */
    public function __construct(private readonly MonitorQueue $queue, private readonly MonitorSuspension $suspension) {}

    /**
     * Issue a new queue key (returned once), or revoke the key and pause the monitor.
     *
     * @param  User  $actor
     * @param  Monitor  $monitor
     * @param  int  $version
     * @param  bool  $revoke
     * @return string|null
     */
    public function handle(User $actor, Monitor $monitor, int $version, bool $revoke = false): ?string
    {
        return DB::transaction(function () use ($actor, $monitor, $version, $revoke): ?string {
            $monitor = $this->queue->lockMonitor($monitor->id);
            abort_unless($monitor !== null && ! $monitor->trashed() && $monitor->type === 'queue', 404);
            Gate::forUser($actor)->authorize('rotateKey', $monitor);
            StateConflict::unlessVersion($monitor->state_version, $version, __('This monitor changed. Refresh before trying again.'));
            if ($revoke) {
                $this->suspension->queue($monitor, revoke: true);

                return null;
            }
            $secret = 'bqk_'.Str::random(64);
            $monitor->forceFill(['queue_token_hash' => hash('sha256', $secret), 'state_version' => $monitor->state_version + 1])->save();

            return $secret;
        }, attempts: 3);
    }
}
