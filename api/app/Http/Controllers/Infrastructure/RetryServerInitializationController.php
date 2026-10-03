<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\RetryServerInitialization;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class RetryServerInitializationController
{
    /**
     * Try a failed server initialisation again.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  RetryServerInitialization  $retry
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Server $server, RetryServerInitialization $retry): RedirectResponse
    {
        $queued = $retry->handle($project->account, $user, $server);

        return to_route('infrastructure.servers.show', [$project, $server->id])->with('status', $queued ? __('Trying again.') : __('This server isn’t waiting for a retry.'));
    }
}
