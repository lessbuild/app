<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SaveMonitor;
use App\Http\Requests\Monitoring\MonitorRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class StoreMonitorController
{
    public function __invoke(MonitorRequest $request, #[CurrentUser] User $user, Project $project, SaveMonitor $save): RedirectResponse
    {
        $monitor = $save->handle($project, $user, $request->validated());

        return to_route('monitoring.monitors.show', [$project, $monitor->id])->with('status', __('Monitor created.'));
    }
}
