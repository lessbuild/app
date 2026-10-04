<?php

declare(strict_types=1);

namespace App\Http\Controllers\SiteAudits;

use App\Models\Project;
use App\Models\SiteAuditRun;
use App\Queries\SiteAudits\SiteAuditQuery;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/projects/{project}/audit/runs/{siteAuditRun}`. */
final class ShowSiteAuditReportController
{
    /**
     * Return a run's report, or its progress while it runs (the page polls this).
     *
     * @param  Project  $project
     * @param  SiteAuditRun  $siteAuditRun
     * @param  SiteAuditQuery  $query
     * @return JsonResponse
     */
    public function __invoke(Project $project, SiteAuditRun $siteAuditRun, SiteAuditQuery $query): JsonResponse
    {
        return response()->json(['report' => $query->report($siteAuditRun)]);
    }
}
