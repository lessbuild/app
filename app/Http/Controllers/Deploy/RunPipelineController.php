<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\RunPipeline;
use App\Models\DeployPipeline;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class RunPipelineController
{
    /**
     * Start a run of the pipeline and return to the pipelines. Another project's pipelines are a 404.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  int  $pipeline
     * @param  RunPipeline  $run
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, int $pipeline, RunPipeline $run): RedirectResponse
    {
        $run->handle($user, DeployPipeline::query()->where('project_id', $project->id)->findOrFail($pipeline));

        return to_route('deploy.pipelines', $project)->with('status', __('Pipeline started.'));
    }
}
