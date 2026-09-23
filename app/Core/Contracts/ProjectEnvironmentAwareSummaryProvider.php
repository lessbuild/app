<?php

namespace App\Core\Contracts;

use App\Core\Data\Projects\ProjectProductSnapshot;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectEnvironment;

interface ProjectEnvironmentAwareSummaryProvider extends ProjectProductSummaryProvider
{
    public function summarizeForEnvironment(
        PlatformUser $user,
        Project $project,
        ProjectEnvironment $environment,
    ): ?ProjectProductSnapshot;
}
