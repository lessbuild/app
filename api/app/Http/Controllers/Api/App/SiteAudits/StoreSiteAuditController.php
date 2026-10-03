<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\App\SiteAudits;

use App\Actions\SiteAudits\QueueSiteAuditRun;
use App\Actions\SiteAudits\SaveSiteAudit;
use App\Enums\SiteAuditSchedule;
use App\Http\Requests\SiteAudits\SaveSiteAuditRequest;
use App\Models\Project;
use App\Models\User;
use App\Queries\SiteAudits\SiteAuditQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `POST /api/app/projects/{project}/audit`. */
final class StoreSiteAuditController
{
    /**
     * Create an audit from the wizard and, unless asked not to, start its first run.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  SaveSiteAuditRequest  $request
     * @param  SaveSiteAudit  $save
     * @param  QueueSiteAuditRun  $queue
     * @param  SiteAuditQuery  $query
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, SaveSiteAuditRequest $request, SaveSiteAudit $save, QueueSiteAuditRun $queue, SiteAuditQuery $query): JsonResponse
    {
        $audit = $save->handle($user, $project, null, (string) $request->validated('name'), (string) $request->validated('url'), $request->goals(),
            $request->validated('custom_goal'), $request->competitors(), SiteAuditSchedule::from((string) $request->validated('schedule')));
        $run = $request->boolean('run_now', true) ? $queue->handle($audit, $user) : null;

        return response()->json(['audit' => $query->detail($user, $audit), 'runId' => $run?->id], 201);
    }
}
