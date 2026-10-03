<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\App\SiteAudits;

use App\Models\Project;
use App\Models\SiteAudit;
use App\Models\User;
use App\Queries\SiteAudits\SiteAuditQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/projects/{project}/audit/{siteAudit}`. */
final class ShowSiteAuditController
{
    /**
     * Return one audit with its competitors, runs and the plan's limits.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  SiteAudit  $siteAudit
     * @param  SiteAuditQuery  $query
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, SiteAudit $siteAudit, SiteAuditQuery $query): JsonResponse
    {
        return response()->json(['audit' => $query->detail($user, $siteAudit), 'goals' => $query->goals()]);
    }
}
