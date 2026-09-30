<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\InstallServerService;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class InstallServerServiceController
{
    /**
     * Install a one-click service on a server (or change where it listens) and return to the Services tab.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  InstallServerService  $install
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Server $server, InstallServerService $install): RedirectResponse
    {
        $service = $install->handle($user, $server, (string) $request->input('kind'), (string) $request->input('listen', 'local'));

        return to_route('infrastructure.servers.show', [$project, $server->id, 'tab' => 'services'])->with('status', __(':service is being installed. It takes a minute or two.', ['service' => $service->name()]));
    }
}
