<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\RunServerCommand;
use App\Models\Project;
use App\Models\User;
use App\Queries\Infrastructure\ServersQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class StoreServerCommandController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, string $server, ServersQuery $servers, RunServerCommand $run): RedirectResponse
    {
        $validated = $request->validate(['command' => ['required', 'string', 'max:4096']]);
        $execution = $run->handle($project->account, $user, $servers->find($project->account_id, $server), (string) $validated['command']);

        return to_route('infrastructure.servers.commands', [$project, (int) $server, 'output' => $execution->id])->with('status', __('Command queued.'));
    }
}
