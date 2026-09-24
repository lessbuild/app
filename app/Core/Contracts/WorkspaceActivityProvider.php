<?php

namespace App\Core\Contracts;

use App\Core\Data\Projects\WorkspaceActivitySnapshot;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\Workspace;
use Illuminate\Support\Collection;

interface WorkspaceActivityProvider
{
    /**
     * @param  Collection<int, Project>  $projects  Active, membership-visible projects with at least one active product association.
     */
    public function recentForWorkspace(
        PlatformUser $user,
        Workspace $workspace,
        Collection $projects,
        int $limit,
    ): WorkspaceActivitySnapshot;
}
