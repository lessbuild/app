<?php

namespace App\Core\Contracts;

use App\Core\Data\Search\WorkspaceSearchResult;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;

interface WorkspaceSearchProvider
{
    /** @return list<WorkspaceSearchResult> */
    public function search(PlatformUser $user, Workspace $workspace, string $query): array;
}
