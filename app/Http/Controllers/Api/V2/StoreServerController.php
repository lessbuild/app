<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Actions\Infrastructure\CreateServer;
use App\Http\Requests\Infrastructure\ServerRequest;
use App\Models\Server;
use App\Models\User;
use App\Support\Api\ResourceJson;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class StoreServerController
{
    /**
     * Create a server in one of the account's cloud providers (`POST /api/v2/servers`). It answers at once with the
     * server queued; poll it until its status is active (about ten minutes) or failed.
     *
     * @param  ServerRequest  $request
     * @param  User  $user
     * @param  CreateServer  $create
     * @return JsonResponse
     */
    public function __invoke(ServerRequest $request, #[CurrentUser] User $user, CreateServer $create): JsonResponse
    {
        $server = $create->handle(ResourceJson::account($request), $user, $request->serverDetails());

        return response()->json(['data' => ResourceJson::server($server)], $server->provisioning_status === Server::STATUS_FAILED ? 422 : 201);
    }
}
