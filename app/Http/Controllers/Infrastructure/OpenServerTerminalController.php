<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\OpenServerTerminal;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class OpenServerTerminalController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Server $server, OpenServerTerminal $open): RedirectResponse
    {
        $request->validate(['columns' => ['nullable', 'integer', 'between:20,240'], 'rows' => ['nullable', 'integer', 'between:5,100']]);
        [$terminal, $token] = $open->handle($user, $server, $request->integer('columns', 120), $request->integer('rows', 32));
        $request->session()->put("terminals.{$terminal->id}", $token);

        return to_route('infrastructure.servers.terminal.show', [$project, $server->id, $terminal->id]);
    }
}
