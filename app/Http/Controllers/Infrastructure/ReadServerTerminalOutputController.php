<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\ReadServerTerminalOutput;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerTerminalSession;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ReadServerTerminalOutputController
{
    /**
     * Returns the terminal's output after the browser's cursor, never cached.
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Server $server, ServerTerminalSession $terminal, ReadServerTerminalOutput $read): JsonResponse
    {
        $request->validate(['after' => ['nullable', 'integer', 'min:0']]);
        $output = $read->handle($user, $terminal, (string) $request->session()->get("terminals.{$terminal->id}", ''), $request->integer('after'));

        return response()->json(['data' => $output])->header('Cache-Control', 'no-store');
    }
}
