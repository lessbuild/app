<?php

namespace App\Services;

use App\Models\Environment;
use App\Models\Repository;

class DeploymentEnvironmentResolver
{
    /**
     * Find a same-workspace website environment, preferring matching branches and then production.
     *
     * @param  Repository  $repository  The repository supplying website, workspace and branch constraints.
     * @return Environment|null The preferred environment, or null if no owned environment is attached.
     */
    public function for(Repository $repository): ?Environment
    {
        return Environment::query()
            ->where('website_id', $repository->website_id)
            ->whereHas('project', fn ($query) => $query->where('organization_id', $repository->organization_id))
            ->orderByRaw('CASE WHEN branch = ? THEN 0 ELSE 1 END', [$repository->branch])
            ->orderByRaw("CASE WHEN type = 'production' THEN 0 ELSE 1 END")
            ->first();
    }
}
