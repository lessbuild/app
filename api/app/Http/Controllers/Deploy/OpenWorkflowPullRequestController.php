<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Environment;
use App\Models\Project;
use App\Models\Repository;
use App\Services\Deploy\GitHubWorkflowPullRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class OpenWorkflowPullRequestController
{
    /**
     * Open (or update) the pull request that adds one of the environment's repositories' build workflow.
     *
     * @param  Request  $request
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  GitHubWorkflowPullRequests  $pullRequests
     * @return JsonResponse
     */
    public function __invoke(Request $request, Project $project, Environment $environment, GitHubWorkflowPullRequests $pullRequests): JsonResponse
    {
        $data = $request->validate(['repository_id' => ['required', 'integer']]);
        $repository = Repository::query()->where('environment_id', $environment->id)->with(['provider', 'website', 'environment'])->findOrFail((int) $data['repository_id']);
        try {
            $url = $pullRequests->open($repository);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['repository_id' => $exception->getMessage()]);
        }

        return response()->json(['redirect' => route('deploy.environments.show', [$project, $environment, 'tab' => 'settings'], false), 'message' => __('Pull request ready: :url', ['url' => $url])]);
    }
}
