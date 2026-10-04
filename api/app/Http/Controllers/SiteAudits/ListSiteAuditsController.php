<?php

declare(strict_types=1);

namespace App\Http\Controllers\SiteAudits;

use App\Models\Project;
use App\Models\User;
use App\Queries\SiteAudits\SiteAuditQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `GET /api/app/projects/{project}/audit`. */
final class ListSiteAuditsController
{
    /**
     * Return the project's audits, the plan's limits and the journeys people can choose.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  SiteAuditQuery  $query
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, SiteAuditQuery $query): JsonResponse
    {
        return response()->json(['audits' => $query->audits($project), 'plan' => $query->plan($user, $project), 'goals' => $query->goals()]);
    }
}
