<?php

namespace App\Services;

use App\Models\Environment;
use App\Models\Repository;

class DeploymentGate
{
    /**
     * Bind environment selection for deployment gate checks.
     *
     * @param  DeploymentEnvironmentResolver  $environments  Resolves the repository's preferred environment.
     */
    public function __construct(private readonly DeploymentEnvironmentResolver $environments) {}

    /**
     * Resolve the environment whose deployment controls apply to the repository.
     *
     * @param  Repository  $repository  The deployment target to resolve.
     * @return Environment|null The matching environment, or null when no eligible same-workspace environment is attached.
     */
    public function environment(Repository $repository): ?Environment
    {
        return $this->environments->for($repository);
    }

    /**
     * Read the selected environment's current deployment restriction.
     *
     * @param  Repository  $repository  The repository whose environment controls must be checked.
     * @return string|null A blocking reason, or null when no selected environment blocks deployment.
     */
    public function blockReason(Repository $repository): ?string
    {
        return $this->environment($repository)?->deploymentBlockReason();
    }
}
