<?php

namespace App\Services;

use App\Models\Environment;
use App\Models\Repository;

class DeploymentGate
{
    public function __construct(private readonly DeploymentEnvironmentResolver $environments) {}

    public function environment(Repository $repository): ?Environment
    {
        return $this->environments->for($repository);
    }

    public function blockReason(Repository $repository): ?string
    {
        return $this->environment($repository)?->deploymentBlockReason();
    }
}
