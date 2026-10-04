<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\RunServerDiagnostics;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class RunServerDiagnosticsController
{
    /**
     * Start a diagnostic run and opens the diagnostics tab.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  RunServerDiagnostics  $diagnose
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Server $server, RunServerDiagnostics $diagnose): JsonResponse
    {
        $diagnose->handle($project->account, $user, $server);

        return response()->json(['redirect' => route('infrastructure.servers.show', [$project, $server->id, 'tab' => 'diagnostics'], false)]);
    }
}
