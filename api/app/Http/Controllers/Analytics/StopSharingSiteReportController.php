<?php

declare(strict_types=1);

namespace App\Http\Controllers\Analytics;

use App\Actions\Analytics\StopSharingSiteReport;
use App\Models\AnalyticsSite;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class StopSharingSiteReportController
{
    /**
     * Stop sharing a site's report; its link stops working.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  AnalyticsSite  $site
     * @param  StopSharingSiteReport  $stop
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, AnalyticsSite $site, StopSharingSiteReport $stop): JsonResponse
    {
        $stop->handle($user, $site);

        return response()->json(['redirect' => route('analytics.sites.show', [$project, $site], false), 'message' => __('The report is no longer shared.')]);
    }
}
