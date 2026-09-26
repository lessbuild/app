<?php

namespace App\Core\Contracts;

use App\Core\Data\Projects\ProjectResourceCandidate;
use App\Core\Models\PlatformUser;

interface ProjectResourceLinkProvider
{
    /** @return list<ProjectResourceCandidate> */
    public function candidates(PlatformUser $user): array;

    public function candidate(PlatformUser $user, string $selectionKey): ?ProjectResourceCandidate;
}
