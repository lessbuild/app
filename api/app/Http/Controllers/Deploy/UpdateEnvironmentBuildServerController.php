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
        $data = $request->validate(['build_server_id' => ['nullable', 'integer'], 'artifact_bucket_id' => ['nullable', 'integer']]);
        $server = isset($data['build_server_id']) ? Server::query()->where('account_id', $project->account_id)->findOrFail((int) $data['build_server_id']) : null;
        $bucket = isset($data['artifact_bucket_id']) ? StorageBucket::query()->where('project_id', $project->id)->findOrFail((int) $data['artifact_bucket_id']) : null;
        $save->handle($user, $environment, $server, $bucket);

        return response()->json(['redirect' => route('deploy.environments.show', [$project, $environment, 'tab' => 'settings'], false), 'message' => $server !== null ? __('Deploys build on :server from the next one.', ['server' => $server->name]) : __('Deploys build on each website’s server from the next one.')]);
    }
}
