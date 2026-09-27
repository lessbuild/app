<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\StatusPageReportQuery;
use App\Queries\Monitoring\StatusPagesQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

/** A status page as the team sees it: what's public right now, and the updates they've posted. */
final class ShowStatusPageController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $page, ProjectOverviewQuery $overview, StatusPagesQuery $pages, StatusPageReportQuery $report): View
    {
        $statusPage = $pages->find($project->account_id, $page);

        return view('monitoring.status-page', [
            'overview' => $overview->handle($project, $user),
            'page' => $statusPage,
            'report' => $report->handle($statusPage),
            'updates' => $statusPage->updates()->orderByDesc('starts_at')->orderByDesc('id')->limit(50)->get(),
            'subscribers' => $statusPage->subscriptions()->whereNotNull('verified_at')->count(),
            'canManage' => $user->can('update', $project->account),
        ]);
    }
}
