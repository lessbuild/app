<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\CancelServerCommand;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class CancelServerCommandController
{
    /**
     * Cancels a queued command; one that already started can't be.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  string  $execution
     * @param  CancelServerCommand  $cancel
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Server $server, string $execution, CancelServerCommand $cancel): RedirectResponse
    {
        $canceled = $cancel->handle($project->account, $user, $server->commandExecutions()->findOrFail((int) $execution));

        return to_route('infrastructure.servers.commands', [$project, $server->id])->with('status', $canceled ? __('Command canceled.') : __('That command already started, so it can’t be canceled.'));
    }
}
