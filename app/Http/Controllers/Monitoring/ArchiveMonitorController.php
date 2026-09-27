<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\ArchiveMonitor;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\ProjectMonitorsQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ArchiveMonitorController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, string $monitor, ProjectMonitorsQuery $monitors, ArchiveMonitor $archive): RedirectResponse
    {
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:0']])['version'];
        $target = $monitors->find($project, $monitor);
        $archive->handle($target, $user, $version);

        return to_route('monitoring.monitors', $project)->with('status', __(':monitor was archived. Its history is kept.', ['monitor' => $target->name]));
    }
}
