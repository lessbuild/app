<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\OpenServerTerminal;
use App\Data\Infrastructure\TerminalSize;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class OpenServerTerminalController
{
    /**
     * Opens a terminal at the browser's window size and keeps its token in this browser's session, so only this browser
     * can type into it.
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Server $server, OpenServerTerminal $open): RedirectResponse
    {
        $request->validate([
            'columns' => ['nullable', 'integer', 'between:'.TerminalSize::MIN_COLUMNS.','.TerminalSize::MAX_COLUMNS],
            'rows' => ['nullable', 'integer', 'between:'.TerminalSize::MIN_ROWS.','.TerminalSize::MAX_ROWS],
        ]);
        [$terminal, $token] = $open->handle($user, $server, $request->integer('columns', 120), $request->integer('rows', 32));
        $request->session()->put("terminals.{$terminal->id}", $token);

        return to_route('infrastructure.servers.terminal.show', [$project, $server->id, $terminal->id]);
    }
}
