<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\CancelServerCommand;
use App\Models\Project;
use App\Models\User;
use App\Queries\Infrastructure\ServersQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class CancelServerCommandController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $server, string $execution, ServersQuery $servers, CancelServerCommand $cancel): RedirectResponse
    {
        $record = $servers->find($project->account_id, $server);
        $canceled = $cancel->handle($project->account, $user, $record->commandExecutions()->findOrFail((int) $execution));

        return to_route('infrastructure.servers.commands', [$project, $record->id])->with('status', $canceled ? __('Command canceled.') : __('That command already started, so it can’t be canceled.'));
    }
}
