<?php

namespace App\Core\Contracts;

use App\Core\Data\Projects\ProjectEnvironmentContext;
use App\Core\Data\Projects\ProjectInfrastructureSnapshot;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectResource;
use Illuminate\Support\Collection;

interface ProjectInfrastructureProvider
{
    /**
     * Project live, authorized native relationships without creating another ownership model.
     * Every edge must reference returned nodes; hidden relationships and their counts are omitted.
     *
     * @param  Collection<int, ProjectResource>  $resources  Current canonical mappings for this product/project.
     */
    public function forProject(
        PlatformUser $user,
        Project $project,
        Collection $resources,
        ?ProjectEnvironmentContext $environmentContext = null,
    ): ProjectInfrastructureSnapshot;
}
