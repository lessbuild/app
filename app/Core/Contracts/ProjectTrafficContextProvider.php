<?php

namespace App\Core\Contracts;

use App\Core\Data\Projects\ProjectTrafficWindowSummary;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectResource;
use Carbon\CarbonImmutable;

interface ProjectTrafficContextProvider
{
    public function aggregate(
        PlatformUser $user,
        Project $project,
        ProjectResource $resource,
        CarbonImmutable $from,
        CarbonImmutable $until,
    ): ?ProjectTrafficWindowSummary;
}
