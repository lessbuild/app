<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\CreateServer;
use App\Http\Requests\Infrastructure\ServerRequest;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class StoreServerController
{
    /**
     * Create a server, showing its root password once, or explaining that the provider failed and nothing was left
     * running.
     *
     * @param  ServerRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  CreateServer  $create
     * @return RedirectResponse
     */
    public function __invoke(ServerRequest $request, #[CurrentUser] User $user, Project $project, CreateServer $create): RedirectResponse
    {
        $server = $create->handle($project->account, $user, $request->serverDetails());

        return to_route('infrastructure.servers.show', [$project, $server->id])->with($server->provisioning_status === Server::STATUS_FAILED
            ? ['error' => __('The provider couldn’t create the server. Nothing was left running.')]
            : ['status' => __('Server created. Provisioning takes about ten minutes.'), 'secrets' => array_filter(['root' => $server->provisioningRootPassword(), 'mysql' => $server->mysql_root_password])]);
    }
}
