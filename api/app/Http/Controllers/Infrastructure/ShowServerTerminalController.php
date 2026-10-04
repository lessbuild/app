<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Models\Project;
use App\Models\Server;
use App\Models\ServerTerminalSession;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowServerTerminalController
{
    /**
     * Describe a terminal session on a server: whether it's open, and whether it belongs to this browser session (its
     * token lives in the session, so only the browser that opened it can type into it).
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  ServerTerminalSession  $terminal
     * @param  ProjectOverviewQuery  $overview
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Server $server, ServerTerminalSession $terminal, ProjectOverviewQuery $overview): JsonResponse
    {
        return response()->json([
            'overview' => $overview->handle($project, $user),
            'server' => ['id' => $server->id, 'label' => $server->label()],
            'terminal' => [
                'id' => $terminal->id,
                'active' => $terminal->isActive(),
                'status' => $terminal->status,
                'closeReason' => $terminal->close_reason,
                'columns' => $terminal->columns,
                'rows' => $terminal->rows,
            ],
            'ownBrowser' => is_string($request->session()->get("terminals.{$terminal->id}")),
            'idleMinutes' => (int) config('infrastructure.terminal.idle_minutes'),
            'sessionMinutes' => (int) config('infrastructure.terminal.session_minutes'),
        ]);
    }
}
