<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\CloseServerTerminal;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerTerminalSession;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class CloseServerTerminalController
{
    /**
     * Closes the terminal and forgets its browser token.
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Server $server, ServerTerminalSession $terminal, CloseServerTerminal $close): RedirectResponse
    {
        $close->handle($user, $terminal);
        $request->session()->forget("terminals.{$terminal->id}");

        return to_route('infrastructure.servers.show', [$project, $server->id])->with('status', __('Terminal closed.'));
    }
}
