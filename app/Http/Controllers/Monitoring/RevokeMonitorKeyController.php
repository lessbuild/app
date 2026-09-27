<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\RotateHeartbeatToken;
use App\Actions\Monitoring\RotateQueueToken;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\ProjectMonitorsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Revoke a heartbeat or queue key and pause the monitor. */
final class RevokeMonitorKeyController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, string $monitor, ProjectMonitorsQuery $monitors, RotateHeartbeatToken $heartbeats, RotateQueueToken $queues): RedirectResponse
    {
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:0']])['version'];
        $target = $monitors->find($project, $monitor);
        abort_unless(in_array($target->type, ['heartbeat', 'queue'], true), 404);
        $target->type === 'heartbeat'
            ? $heartbeats->handle($user, $target, $version, revoke: true)
            : $queues->handle($user, $target, $version, revoke: true);

        return to_route('monitoring.monitors.show', [$project, $target->id])->with('status', __('Key revoked and monitor paused.'));
    }
}
