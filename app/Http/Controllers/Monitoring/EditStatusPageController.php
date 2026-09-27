<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\StatusPagesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

final class EditStatusPageController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $page, ProjectOverviewQuery $overview, StatusPagesQuery $pages): View
    {
        $statusPage = $pages->find($project->account_id, $page);
        Gate::authorize('update', $project->account);

        return view('monitoring.status-page-form', [
            'overview' => $overview->handle($project, $user),
            'page' => $statusPage,
            'monitors' => $pages->monitors($project->account),
        ]);
    }
}
