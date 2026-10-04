<?php

declare(strict_types=1);

namespace App\Http\Controllers\SiteAudits;

use App\Actions\SiteAudits\DeleteSiteAudit;
use App\Models\Project;
use App\Models\SiteAudit;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

/** `DELETE /api/app/projects/{project}/audit/{siteAudit}`. */
final class DeleteSiteAuditController
{
    /**
     * Delete an audit with its runs and files, and send the app back to the audits.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  SiteAudit  $siteAudit
     * @param  DeleteSiteAudit  $delete
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, SiteAudit $siteAudit, DeleteSiteAudit $delete): JsonResponse
    {
        $delete->handle($user, $siteAudit);

        return response()->json(['redirect' => route('audit.index', $project, false), 'message' => __(':audit was deleted.', ['audit' => $siteAudit->name])]);
    }
}
