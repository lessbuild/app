<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\CloseServerTerminal;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerTerminalSession;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CloseServerTerminalController
{
    /**
     * Close the terminal and forgets its browser token.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  ServerTerminalSession  $terminal
     * @param  CloseServerTerminal  $close
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Server $server, ServerTerminalSession $terminal, CloseServerTerminal $close): JsonResponse
    {
        $close->handle($user, $terminal);
        $request->session()->forget("terminals.{$terminal->id}");

        return response()->json(['redirect' => route('infrastructure.servers.show', [$project, $server->id], false), 'message' => __('Terminal closed.')]);
    }
}
