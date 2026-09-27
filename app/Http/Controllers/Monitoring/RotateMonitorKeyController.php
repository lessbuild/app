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

/** Issue a new heartbeat or queue key; the old one stops working at once. */
final class RotateMonitorKeyController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Monitor $monitor, RotateHeartbeatToken $heartbeats, RotateQueueToken $queues): RedirectResponse
    {
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:0']])['version'];
        $key = $monitor->type === 'heartbeat' ? $heartbeats->handle($user, $monitor, $version) : $queues->handle($user, $monitor, $version);

        return to_route('monitoring.monitors.show', [$project, $monitor->id])->with('issued_key', $key);
    }
}
