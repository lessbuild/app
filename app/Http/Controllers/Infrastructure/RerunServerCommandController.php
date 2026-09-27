<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\RunServerCommand;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class RerunServerCommandController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, Server $server, string $execution, RunServerCommand $run): RedirectResponse
    {
        $rerun = $run->handle($project->account, $user, $server, null, $server->commandExecutions()->findOrFail((int) $execution));

        return to_route('infrastructure.servers.commands', [$project, $server->id, 'output' => $rerun->id])->with('status', __('Command #:id queued again.', ['id' => $execution]));
    }
}
