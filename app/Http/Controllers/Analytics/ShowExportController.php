<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Models\AnalyticsExport;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowExportController
{
    /**
     * An export's page, which shows its progress and the download link.
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, string $token, ProjectOverviewQuery $overview): View
    {
        $export = AnalyticsExport::query()->where('token_hash', hash('sha256', $token))->whereHas('site', fn ($query) => $query->where('project_id', $project->id))->firstOrFail();

        return view('analytics.export', ['overview' => $overview->handle($project, $user), 'export' => $export, 'token' => $token]);
    }
}
