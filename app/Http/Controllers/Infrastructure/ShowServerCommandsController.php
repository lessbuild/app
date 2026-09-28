<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Models\Project;
use App\Models\Server;
use App\Models\ServerCommandExecution;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Run a root command on a server, and the history of what ran. */
final class ShowServerCommandsController
{
    /**
     * The server's command runner and history, optionally filtered by status, with one command's output open.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  ProjectOverviewQuery  $overview
     * @return View
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Server $server, ProjectOverviewQuery $overview): View
    {
        $filters = $request->validate(['status' => ['nullable', Rule::in([...ServerCommandExecution::ACTIVE, ...ServerCommandExecution::FINISHED])], 'output' => ['nullable', 'integer']]);
        $status = is_string($filters['status'] ?? null) ? $filters['status'] : null;

        return view('infrastructure.server-commands', [
            'overview' => $overview->handle($project, $user),
            'server' => $server,
            'executions' => $server->commandExecutions()->with('user')->when($status !== null, fn ($query) => $query->where('status', $status))
                ->orderByDesc('id')->paginate(25)->withQueryString(),
            'status' => $status,
            'selected' => isset($filters['output']) ? $server->commandExecutions()->find((int) $filters['output']) : null,
            'canManage' => $user->can('runCommands', $server),
        ]);
    }
}
