<?php

namespace App\Core\Contracts;

use App\Core\Data\Projects\ProjectSetupStep;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;

interface ProjectSetupProvider
{
    /** @return list<ProjectSetupStep> */
    public function steps(PlatformUser $user, Project $project): array;
}
