<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\SaveEnvironmentBuildServer;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\StorageBucket;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UpdateEnvironmentBuildServerController
{
    /**
     * Save where the environment's deploys build and return to its settings.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  SaveEnvironmentBuildServer  $save
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Environment $environment, SaveEnvironmentBuildServer $save): JsonResponse
    {
        // "github" in place of a server builds on GitHub Actions.
        $data = $request->validate(['build_server_id' => ['nullable', 'regex:/\A([0-9]+|github)\z/'], 'artifact_bucket_id' => ['nullable', 'integer']]);
        $github = ($data['build_server_id'] ?? null) === 'github';
        $server = isset($data['build_server_id']) && ! $github ? Server::query()->where('account_id', $project->account_id)->findOrFail((int) $data['build_server_id']) : null;
        $bucket = isset($data['artifact_bucket_id']) && ! $github ? StorageBucket::query()->where('project_id', $project->id)->findOrFail((int) $data['artifact_bucket_id']) : null;
        $save->handle($user, $environment, $server, $bucket, $github);
        $message = match (true) {
            $github => __('Deploys build on GitHub Actions from the next one. Add the workflow shown here to the repository.'),
            $server !== null => __('Deploys build on :server from the next one.', ['server' => $server->name]),
            default => __('Deploys build on each website’s server from the next one.'),
        };

        return response()->json(['redirect' => route('deploy.environments.show', [$project, $environment, 'tab' => 'settings'], false), 'message' => $message]);
    }
}
