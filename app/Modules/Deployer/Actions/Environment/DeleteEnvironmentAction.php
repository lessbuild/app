<?php

namespace App\Modules\Deployer\Actions\Environment;

use App\Modules\Deployer\Exceptions\EnvironmentDeletionException;
use App\Modules\Deployer\Models\Environment;

class DeleteEnvironmentAction
{
    /**
     * Delete one non-production environment; the caller maps the domain rejection to its existing HTTP response.
     *
     * @throws EnvironmentDeletionException If the protected production environment is targeted.
     */
    public function handle(Environment $environment): bool
    {
        if ($environment->type === 'production') {
            throw new EnvironmentDeletionException('The production environment cannot be deleted.');
        }

        return (bool) $environment->delete();
    }
}
