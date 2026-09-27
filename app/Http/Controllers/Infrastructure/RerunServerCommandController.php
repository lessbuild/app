<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\RunServerCommand;
use App\Models\Project;
use App\Models\User;
use App\Queries\Infrastructure\ServersQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class RerunServerCommandController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $server, string $execution, ServersQuery $servers, RunServerCommand $run): RedirectResponse
    {
        $record = $servers->find($project->account_id, $server);
        $rerun = $run->handle($project->account, $user, $record, null, $record->commandExecutions()->findOrFail((int) $execution));

        return to_route('infrastructure.servers.commands', [$project, $record->id, 'output' => $rerun->id])->with('status', __('Command #:id queued again.', ['id' => $execution]));
    }
}
