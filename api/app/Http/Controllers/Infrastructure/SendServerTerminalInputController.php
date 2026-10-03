<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\SendServerTerminalInput;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerTerminalSession;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SendServerTerminalInputController
{
    /**
     * Queue keystrokes for the terminal and returns their sequence number (202).
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  ServerTerminalSession  $terminal
     * @param  SendServerTerminalInput  $send
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Server $server, ServerTerminalSession $terminal, SendServerTerminalInput $send): JsonResponse
    {
        $request->validate(['input' => ['present', 'string', 'max:'.(int) config('infrastructure.terminal.max_input_bytes')]]);
        $sequence = $send->handle($user, $terminal, (string) $request->session()->get("terminals.{$terminal->id}", ''), $request->string('input')->toString());

        return response()->json(['data' => ['sequence' => $sequence]], 202)->header('Cache-Control', 'no-store');
    }
}
