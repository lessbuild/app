<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure\Storage;

use App\Models\Project;
use App\Models\StorageBucket;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Support\Infrastructure\BackupDestinationPresets;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowStorageController
{
    /**
     * Show the project's storage buckets and which environment each is attached to.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview): View
    {
        return view('infrastructure.storage', [
            'overview' => $overview->handle($project, $user),
            'buckets' => StorageBucket::query()->where('project_id', $project->id)->with('environment')->orderBy('name')->get(),
            'environments' => $project->environments()->orderBy('name')->get(['id', 'name', 'project_id']),
            'presets' => BackupDestinationPresets::all(),
            'canManage' => $user->can('manageService', [$project, 'infrastructure']),
        ]);
    }
}
