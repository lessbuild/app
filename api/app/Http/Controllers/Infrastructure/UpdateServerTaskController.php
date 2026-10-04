<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\SaveServerTask;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use App\Support\Infrastructure\ServerTaskKinds;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UpdateServerTaskController
{
    /**
     * Change a server's cron job, process or firewall rule and return to its tab.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  string  $kind
     * @param  string  $task
     * @param  SaveServerTask  $save
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Server $server, string $kind, string $task, SaveServerTask $save): JsonResponse
    {
        $save->handle($user, $server, ServerTaskKinds::editableModel($kind), $request->all(), ServerTaskKinds::findEditable($server, $kind, $task));

        return response()->json(['redirect' => route('infrastructure.servers.show', [$project, $server->id, 'tab' => ServerTaskKinds::tab($kind)], false), 'message' => __('Saved. The change is being made on the server.')]);
    }
}
