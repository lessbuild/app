<?php

declare(strict_types=1);

namespace App\Actions\Monitoring;

use App\Models\Monitor;
use App\Models\User;
use App\Services\Monitoring\MonitorQueue;
use App\Services\Monitoring\MonitorSuspension;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class RotateQueueToken
{
    public function __construct(private readonly MonitorQueue $queue, private readonly MonitorSuspension $suspension) {}

    /** Issue a new queue key (returned once), or revoke the key and pause the monitor. */
    public function handle(User $actor, Monitor $monitor, int $version, bool $revoke = false): ?string
    {
        return DB::transaction(function () use ($actor, $monitor, $version, $revoke): ?string {
            $monitor = $this->queue->lockMonitor($monitor->id);
            abort_unless($monitor !== null && ! $monitor->trashed(), 404);
            Gate::forUser($actor)->authorize('manageService', [$monitor->environment->project, 'monitoring']);
            abort_unless($actor->hasVerifiedEmail(), 403);
            abort_unless($monitor->type === 'queue', 404);
            abort_unless($monitor->state_version === $version, 409, __('This monitor changed. Refresh before trying again.'));
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
