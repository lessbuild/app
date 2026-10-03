<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\App\SiteAudits;

use App\Actions\SiteAudits\DeleteSiteAudit;
use App\Models\Project;
use App\Models\SiteAudit;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Response;

/** `DELETE /api/app/projects/{project}/audit/{siteAudit}`. */
final class DeleteSiteAuditController
{
    /**
     * Delete an audit with its runs and files.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  SiteAudit  $siteAudit
     * @param  DeleteSiteAudit  $delete
     * @return Response
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, SiteAudit $siteAudit, DeleteSiteAudit $delete): Response
    {
        $delete->handle($user, $siteAudit);

        return response()->noContent();
    }
}
