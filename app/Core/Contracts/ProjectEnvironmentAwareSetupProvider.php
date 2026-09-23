<?php

namespace App\Core\Contracts;

use App\Core\Data\Projects\ProjectSetupStep;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\ProjectEnvironment;

interface ProjectEnvironmentAwareSetupProvider extends ProjectSetupProvider
{
    /** @return list<ProjectSetupStep> */
    public function stepsForEnvironment(
        PlatformUser $user,
        Project $project,
        ProjectEnvironment $environment,
    ): array;
}
