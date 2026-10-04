<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\RunServerCommand;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StoreServerCommandController
{
    /**
     * Queue a command and shows its output as it runs.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  RunServerCommand  $run
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Server $server, RunServerCommand $run): JsonResponse
    {
        $validated = $request->validate(['command' => ['required', 'string', 'max:4096']]);
        $execution = $run->handle($project->account, $user, $server, (string) $validated['command']);

        return response()->json(['redirect' => route('infrastructure.servers.commands', [$project, $server->id, 'output' => $execution->id], false), 'message' => __('Command queued.')]);
    }
}
