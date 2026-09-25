<?php

namespace App\Core\Contracts;

use App\Core\Data\Credentials\WorkspaceCredentialSnapshot;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\Workspace;
use Illuminate\Support\Collection;

interface WorkspaceCredentialProvider
{
    /**
     * Return bounded, non-secret credential metadata for explicitly mapped and authorized product resources.
     *
     * @param  Collection<int, Project>  $projects  Active, membership-visible projects with an active product association.
     */
    public function credentialsForWorkspace(
        PlatformUser $user,
        Workspace $workspace,
        Collection $projects,
        int $limit,
    ): WorkspaceCredentialSnapshot;
}
