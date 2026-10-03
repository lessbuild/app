<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\App\SiteAudits;

use App\Actions\SiteAudits\SaveSiteAudit;
use App\Enums\SiteAuditSchedule;
use App\Http\Requests\SiteAudits\SaveSiteAuditRequest;
use App\Models\Project;
use App\Models\SiteAudit;
use App\Models\User;
use App\Queries\SiteAudits\SiteAuditQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `PUT /api/app/projects/{project}/audit/{siteAudit}`. */
final class UpdateSiteAuditController
{
    /**
     * Change an audit's site, journeys, competitors or schedule.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  SiteAudit  $siteAudit
     * @param  SaveSiteAuditRequest  $request
     * @param  SaveSiteAudit  $save
     * @param  SiteAuditQuery  $query
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, SiteAudit $siteAudit, SaveSiteAuditRequest $request, SaveSiteAudit $save, SiteAuditQuery $query): JsonResponse
    {
        $audit = $save->handle($user, $project, $siteAudit, (string) $request->validated('name'), (string) $request->validated('url'), $request->goals(),
            $request->validated('custom_goal'), $request->competitors(), SiteAuditSchedule::from((string) $request->validated('schedule')));

        return response()->json(['audit' => $query->detail($user, $audit)]);
    }
}
