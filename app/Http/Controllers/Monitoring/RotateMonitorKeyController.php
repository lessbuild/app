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

/** Issue a new heartbeat or queue key; the old one stops working at once. */
final class RotateMonitorKeyController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, string $monitor, ProjectMonitorsQuery $monitors, RotateHeartbeatToken $heartbeats, RotateQueueToken $queues): RedirectResponse
    {
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:0']])['version'];
        $target = $monitors->find($project, $monitor);
        abort_unless(in_array($target->type, ['heartbeat', 'queue'], true), 404);
        $key = $target->type === 'heartbeat' ? $heartbeats->handle($user, $target, $version) : $queues->handle($user, $target, $version);

        return to_route('monitoring.monitors.show', [$project, $target->id])->with('issued_key', $key);
    }
}
