<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\RestartServerProcess;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerProcess;
use App\Models\User;
use App\Support\Infrastructure\ServerTaskKinds;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class RestartServerProcessController
{
    /**
     * Restart every copy of a server's process and return to the Processes tab.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  string  $process
     * @param  RestartServerProcess  $restart
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Server $server, string $process, RestartServerProcess $restart): JsonResponse
    {
        $task = ServerTaskKinds::find($server, 'processes', $process);
        assert($task instanceof ServerProcess);
        $restart->handle($user, $task);

        return response()->json(['redirect' => route('infrastructure.servers.show', [$project, $server->id, 'tab' => 'processes'], false), 'message' => __('Restarting :name.', ['name' => $task->name])]);
    }
}
