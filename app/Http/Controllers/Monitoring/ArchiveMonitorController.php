<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\ArchiveMonitor;
use App\Models\Monitor;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ArchiveMonitorController
{
    /**
     * Archives a monitor, keeping its history, if it hasn't changed since the page was opened.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Monitor  $monitor
     * @param  ArchiveMonitor  $archive
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Monitor $monitor, ArchiveMonitor $archive): RedirectResponse
    {
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:0']])['version'];
        $archive->handle($monitor, $user, $version);

        return to_route('monitoring.monitors', $project)->with('status', __(':monitor was archived. Its history is kept.', ['monitor' => $monitor->name]));
    }
}
