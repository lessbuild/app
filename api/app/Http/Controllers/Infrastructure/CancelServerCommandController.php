<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\CancelServerCommand;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class CancelServerCommandController
{
    /**
     * Cancel a queued command; one that already started can't be.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  string  $execution
     * @param  CancelServerCommand  $cancel
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Server $server, string $execution, CancelServerCommand $cancel): JsonResponse
    {
        $canceled = $cancel->handle($project->account, $user, $server->commandExecutions()->findOrFail((int) $execution));

        return response()->json(['redirect' => route('infrastructure.servers.commands', [$project, $server->id], false), 'message' => $canceled ? __('Command canceled.') : __('That command already started, so it can’t be canceled.')]);
    }
}
