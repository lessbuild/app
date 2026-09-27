<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Models\BackupDestination;
use App\Models\Project;
use App\Models\User;
use App\Queries\Infrastructure\BackupsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Support\Infrastructure\BackupDestinationPresets;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowBackupsController
{
    /**
     * The backups page: destinations, recent backups and when backups, restores and verifications last succeeded.
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, BackupsQuery $backups): View
    {
        return view('infrastructure.backups', [
            'overview' => $overview->handle($project, $user),
            'destinations' => BackupDestination::query()->where('account_id', $project->account_id)->withCount(['schedules', 'backups'])->orderBy('name')->get(),
            'backups' => $backups->recent($project->account_id),
            'summary' => $backups->summary($project->account_id),
            'presets' => BackupDestinationPresets::all(),
            'canManage' => $user->can('create', [BackupDestination::class, $project]),
        ]);
    }
}
