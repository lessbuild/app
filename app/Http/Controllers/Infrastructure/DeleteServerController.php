<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\DeleteServer;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteServerController
{
    /**
     * Deletes a server.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  DeleteServer  $delete
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Server $server, DeleteServer $delete): RedirectResponse
    {
        $delete->handle($project->account, $user, $server);

        return to_route('infrastructure.servers', $project)->with('status', __('Server deleted.'));
    }
}
