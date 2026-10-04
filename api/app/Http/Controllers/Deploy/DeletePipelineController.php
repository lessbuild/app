<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\DeletePipeline;
use App\Models\DeployPipeline;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DeletePipelineController
{
    /**
     * Delete a pipeline and return to the pipelines. Another project's pipelines are a 404.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  int  $pipeline
     * @param  DeletePipeline  $delete
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, int $pipeline, DeletePipeline $delete): JsonResponse
    {
        $delete->handle($user, DeployPipeline::query()->where('project_id', $project->id)->findOrFail($pipeline));

        return response()->json(['redirect' => route('deploy.pipelines', $project, false), 'message' => __('Pipeline deleted.')]);
    }
}
