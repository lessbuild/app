<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\App\SiteAudits;

use App\Actions\SiteAudits\QueueSiteAuditRun;
use App\Data\SiteAudits\SiteAuditRunSummary;
use App\Models\Project;
use App\Models\SiteAudit;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `POST /api/app/projects/{project}/audit/{siteAudit}/runs`. */
final class StartSiteAuditRunController
{
    /**
     * Start a run of an audit, or return the one already going.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  SiteAudit  $siteAudit
     * @param  QueueSiteAuditRun  $queue
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, SiteAudit $siteAudit, QueueSiteAuditRun $queue): JsonResponse
    {
        return response()->json(['run' => SiteAuditRunSummary::from($queue->handle($siteAudit, $user))], 202);
    }
}
