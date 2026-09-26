<?php

namespace App\Core\Contracts;

use App\Core\Models\PlatformUser;
use App\Core\Models\Project;

interface ProjectProductLink
{
    public function resolve(PlatformUser $user, Project $project): ?string;
}
