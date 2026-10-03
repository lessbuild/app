<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\RemoveServerTask;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use App\Support\Infrastructure\ServerTaskKinds;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteServerTaskController
{
    /**
     * Take a cron job, process or firewall rule off a server and return to its tab.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  string  $kind
     * @param  string  $task
     * @param  RemoveServerTask  $remove
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Server $server, string $kind, string $task, RemoveServerTask $remove): RedirectResponse
    {
        $remove->handle($user, ServerTaskKinds::find($server, $kind, $task));

        return to_route('infrastructure.servers.show', [$project, $server->id, 'tab' => ServerTaskKinds::tab($kind)])->with('status', __('It’s being removed from the server.'));
    }
}
