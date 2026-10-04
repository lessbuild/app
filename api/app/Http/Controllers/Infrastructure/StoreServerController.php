<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\CreateServer;
use App\Http\Requests\Infrastructure\ServerRequest;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

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
     * @return JsonResponse
     */
    public function __invoke(ServerRequest $request, #[CurrentUser] User $user, Project $project, CreateServer $create): JsonResponse
    {
        $server = $create->handle($project->account, $user, $request->serverDetails());

        $redirect = route('infrastructure.servers.show', [$project, $server->id], false);
        if ($server->provisioning_status === Server::STATUS_FAILED) {
            return response()->json(['redirect' => $redirect, 'warning' => __('The provider couldn’t create the server. Nothing was left running.')]);
        }

        // The passwords are shown once, on the server's page.
        return response()->json([
            'redirect' => $redirect,
            'message' => __('Server created. Provisioning takes about ten minutes.'),
            'secrets' => array_filter(['root' => $server->provisioningRootPassword(), 'mysql' => $server->mysql_root_password]),
        ]);
    }
}
