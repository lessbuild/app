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
     * Start a run: deploy the first repository now; the rest follow one by one as each succeeds. One run at a time.
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
        $first = Repository::query()->where('project_id', $pipeline->project_id)->findOrFail($pipeline->repository_ids[0]);
        Gate::forUser($actor)->authorize('deploy', $first);
        $run = new DeployPipelineRun;
        $run->forceFill(['pipeline_id' => $pipeline->id, 'started_by' => $actor->id, 'status' => 'running', 'step' => 0, 'build_ids' => []])->save();
        $this->steps->handle($run, $actor, $first);

        return $run;
    }
}
