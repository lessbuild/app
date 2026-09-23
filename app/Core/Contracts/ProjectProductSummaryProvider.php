<?php

namespace App\Core\Contracts;

use App\Core\Data\Projects\ProjectProductSnapshot;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;

interface ProjectProductSummaryProvider
{
    public function summarize(PlatformUser $user, Project $project): ?ProjectProductSnapshot;
}
