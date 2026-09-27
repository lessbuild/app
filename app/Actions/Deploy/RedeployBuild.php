<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Build;
use App\Models\User;

final class RedeployBuild
{
    public function __construct(private readonly DeployRepository $deploy) {}

    /** Build and deploy a past build's commit again, with the repository's current settings. */
    public function handle(User $actor, Build $source): Build
    {
        $build = $this->deploy->handle($actor, $source->repository, $source->revision, 'redeploy');
        $build->forceFill(['redeployed_from_build_id' => $source->id])->save();

        return $build;
    }
}
