<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\DeleteServer;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DeleteServerController
{
    /**
     * Delete a server.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  DeleteServer  $delete
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Server $server, DeleteServer $delete): JsonResponse
    {
        $delete->handle($project->account, $user, $server);

        return response()->json(['redirect' => route('infrastructure.servers', $project, false), 'message' => __('Server deleted.')]);
    }
}
