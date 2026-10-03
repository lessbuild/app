<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\StopSharingSiteReport;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class StopSharingSiteReportController
{
    /**
     * Stop sharing a site's report; its link stops working.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  StopSharingSiteReport  $stop
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, AnalyticsSite $site, StopSharingSiteReport $stop): RedirectResponse
    {
        $stop->handle($user, $site);

        return to_route('analytics.sites.show', [$project, $site])->with('status', __('The report is no longer shared.'));
    }
}
