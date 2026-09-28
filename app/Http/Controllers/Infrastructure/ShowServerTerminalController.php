<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Models\Project;
use App\Models\Server;
use App\Models\ServerTerminalSession;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class ShowServerTerminalController
{
    /**
     * The terminal page. Only the browser that opened the terminal can type into it; others see it read-only.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  ServerTerminalSession  $terminal
     * @param  ProjectOverviewQuery  $overview
     * @return View
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Server $server, ServerTerminalSession $terminal, ProjectOverviewQuery $overview): View
    {
        return view('infrastructure.server-terminal', [
            'overview' => $overview->handle($project, $user),
            'server' => $server,
            'terminal' => $terminal,
            'ownBrowser' => is_string($request->session()->get("terminals.{$terminal->id}")),
        ]);
    }
}
