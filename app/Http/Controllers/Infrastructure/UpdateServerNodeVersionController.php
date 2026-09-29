<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\ChangeRuntimeVersion;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class UpdateServerNodeVersionController
{
    /**
     * Switch the server's Node.js version and return to its settings.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  ChangeRuntimeVersion  $change
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Server $server, ChangeRuntimeVersion $change): RedirectResponse
    {
        $change->handle($user, $server, (string) $request->validate(['node_version' => ['required', 'string', 'max:4']])['node_version']);

        return to_route('infrastructure.servers.show', [$project, $server->id, 'tab' => 'settings'])->with('status', __('Switching the server to Node.js :version.', ['version' => $server->node_version]));
    }
}
