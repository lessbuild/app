<?php

namespace App\Actions\Environment;

use App\Models\EnvironmentProcess;
use App\Models\EnvironmentResource;
use App\Models\EnvironmentVariable;

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
