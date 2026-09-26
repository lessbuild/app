<?php

namespace App\Modules\Deployer\Actions\Environment;

use App\Modules\Deployer\Models\EnvironmentProcess;
use App\Modules\Deployer\Models\EnvironmentResource;
use App\Modules\Deployer\Models\EnvironmentVariable;

class DeleteEnvironmentChildAction
{
    /**
     * Delete one route-scoped environment configuration child.
     */
    public function handle(EnvironmentVariable|EnvironmentProcess|EnvironmentResource $child): bool
    {
        return (bool) $child->delete();
    }
}
