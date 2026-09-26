<?php

namespace App\Core\Contracts;

use App\Core\Data\Connections\ProjectConnectionDiagnostic;
use App\Core\Models\PlatformUser;
use App\Core\Models\ProjectConnection;
use App\Core\Models\ProjectResource;

interface ProjectConnectionDiagnosticProvider
{
    public function diagnose(
        PlatformUser $user,
        ProjectConnection $connection,
        ProjectResource $resource,
    ): ?ProjectConnectionDiagnostic;
}
