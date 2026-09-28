<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\DeleteServerCommand;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteServerCommandController
{
    /**
     * Deletes a finished command from the history; queued or running ones stay.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  string  $execution
     * @param  DeleteServerCommand  $delete
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Server $server, string $execution, DeleteServerCommand $delete): RedirectResponse
    {
        $deleted = $delete->handle($project->account, $user, $server->commandExecutions()->findOrFail((int) $execution));

        return to_route('infrastructure.servers.commands', [$project, $server->id])->with('status', $deleted ? __('Command deleted from the history.') : __('Queued or running commands can’t be deleted.'));
    }
}
