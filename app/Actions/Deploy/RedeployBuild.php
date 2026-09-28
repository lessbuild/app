<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Build;
use App\Models\User;

final class RedeployBuild
{
    /**
     * Create a new RedeployBuild instance.
     *
     * Deploys a finished build's commit again.
     *
     * @param  DeployRepository  $deploy  Queues the new deploy the same way a manual deploy is queued.
     */
    public function __construct(private readonly DeployRepository $deploy) {}

    /**
     * Build and deploy a past build's commit again, with the repository's current settings.
     *
     * @param  User  $actor
     * @param  Build  $source
     * @return Build
     */
    public function handle(User $actor, Build $source): Build
    {
        $build = $this->deploy->handle($actor, $source->repository, $source->revision, 'redeploy');
        $build->forceFill(['redeployed_from_build_id' => $source->id])->save();

        return $build;
    }
}
