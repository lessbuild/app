<?php

namespace App\Core\Contracts;

use App\Core\Models\PlatformUser;
use App\Core\Models\ProjectResource;
use Illuminate\Support\Collection;

interface ProjectResourceDestinationProvider
{
    /**
     * Resolve product-local destinations for resources the user can currently access.
     *
     * @param  Collection<int, ProjectResource>  $resources
     * @return array<string, string>
     */
    public function destinations(PlatformUser $user, Collection $resources): array;
}
