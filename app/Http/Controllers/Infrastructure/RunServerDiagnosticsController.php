<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\RunServerDiagnostics;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class RunServerDiagnosticsController
{
    /**
     * Starts a diagnostic run and opens the diagnostics tab.
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Server $server, RunServerDiagnostics $diagnose): RedirectResponse
    {
        $diagnose->handle($project->account, $user, $server);

        return to_route('infrastructure.servers.show', [$project, $server->id, 'tab' => 'diagnostics']);
    }
}
