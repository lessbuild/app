<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\RunServerCommand;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class RerunServerCommandController
{
    /**
     * Queue a past command again and shows its output as it runs.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  string  $execution
     * @param  RunServerCommand  $run
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Server $server, string $execution, RunServerCommand $run): JsonResponse
    {
        $rerun = $run->handle($project->account, $user, $server, null, $server->commandExecutions()->findOrFail((int) $execution));

        return response()->json(['redirect' => route('infrastructure.servers.commands', [$project, $server->id, 'output' => $rerun->id], false), 'message' => __('Command #:id queued again.', ['id' => $execution])]);
    }
}
