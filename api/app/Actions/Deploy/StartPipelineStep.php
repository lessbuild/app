<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Build;
use App\Models\DeployPipelineRun;
use App\Models\Repository;
use App\Models\User;
use Throwable;

final class StartPipelineStep
{
    /**
     * Create a new StartPipelineStep instance.
     *
     * @param  DeployRepository  $deploy  Starts the deploy.
     */
    public function __construct(private readonly DeployRepository $deploy) {}

    /**
     * Deploy one step's repository for a pipeline run, as the person who started the run, or stop the run when the
     * deploy can't start.
     *
     * @param  DeployPipelineRun  $run
     * @param  User  $actor
     * @param  Repository  $repository
     * @return Build|null
     */
    public function handle(DeployPipelineRun $run, User $actor, Repository $repository): ?Build
    {
        try {
            $build = $this->deploy->handle($actor, $repository, null, 'pipeline');
        } catch (Throwable $exception) {
            $run->forceFill(['status' => 'failed', 'failure' => mb_substr(__('Couldn’t start :repository: :reason', ['repository' => $repository->name, 'reason' => $exception->getMessage()]), 0, 500), 'finished_at' => now()])->save();

            return null;
        }
        $run->forceFill(['build_ids' => [...$run->build_ids, $build->id]])->save();

        return $build;
    }
}
