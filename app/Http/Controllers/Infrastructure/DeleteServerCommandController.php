<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\DeleteServerCommand;
use App\Models\Project;
use App\Models\User;
use App\Queries\Infrastructure\ServersQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteServerCommandController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $server, string $execution, ServersQuery $servers, DeleteServerCommand $delete): RedirectResponse
    {
        $record = $servers->find($project->account_id, $server);
        $deleted = $delete->handle($project->account, $user, $record->commandExecutions()->findOrFail((int) $execution));

        return to_route('infrastructure.servers.commands', [$project, $record->id])->with('status', $deleted ? __('Command deleted from the history.') : __('Queued or running commands can’t be deleted.'));
    }
}
