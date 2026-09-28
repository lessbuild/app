<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\RotateHeartbeatToken;
use App\Actions\Monitoring\RotateQueueToken;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Revoke a heartbeat or queue key and pause the monitor. */
final class RevokeMonitorKeyController
{
    /**
     * Revokes the monitor's key and pauses it.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Monitor  $monitor
     * @param  RotateHeartbeatToken  $heartbeats
     * @param  RotateQueueToken  $queues
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Monitor $monitor, RotateHeartbeatToken $heartbeats, RotateQueueToken $queues): RedirectResponse
    {
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:0']])['version'];
        $monitor->type === 'heartbeat'
            ? $heartbeats->handle($user, $monitor, $version, revoke: true)
            : $queues->handle($user, $monitor, $version, revoke: true);

        return to_route('monitoring.monitors.show', [$project, $monitor->id])->with('status', __('Key revoked and monitor paused.'));
    }
}
