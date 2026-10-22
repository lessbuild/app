<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Exceptions\AccountRuleViolation;
use App\Models\DeployPipeline;
use App\Models\DeployPipelineRun;
use App\Models\Repository;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class RunPipeline
{
    /**
     * Create a new RunPipeline instance.
     *
     * @param  StartPipelineStep  $steps  Starts each step's deploy.
     */
    public function __construct(private readonly StartPipelineStep $steps) {}

    /**
     * Start a run: deploy the first step now (with any steps set to start alongside it); each next group follows once
     * every deploy in the one before is live. One run at a time.
     *
     * @param  User  $actor
     * @param  DeployPipeline  $pipeline
     * @return DeployPipelineRun
     */
    public function handle(User $actor, DeployPipeline $pipeline): DeployPipelineRun
    {
        if ($pipeline->runs()->where('status', 'running')->exists()) {
            throw new AccountRuleViolation('pipeline', __('This pipeline is already running.'));
        }
        $first = Repository::query()->where('project_id', $pipeline->project_id)->whereKey($pipeline->groups()[0])->get()->sortBy(fn (Repository $repository): int => (int) array_search($repository->id, $pipeline->repository_ids, true));
        foreach ($first as $repository) {
            Gate::forUser($actor)->authorize('deploy', $repository);
        }
        $run = new DeployPipelineRun;
        $run->forceFill(['pipeline_id' => $pipeline->id, 'started_by' => $actor->id, 'status' => 'running', 'step' => 0, 'build_ids' => []])->save();
        // The first group's deploys start together; the next group starts once they're all live.
        foreach ($first as $repository) {
            if ($run->status === 'running') {
                $this->steps->handle($run, $actor, $repository);
            }
        }

        return $run;
    }
}
