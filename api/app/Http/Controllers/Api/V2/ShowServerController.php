<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Models\Server;
use App\Models\User;
use App\Support\Api\ResourceJson;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ShowServerController
{
    /**
     * Show a server, with its provisioning status (`GET /api/v2/servers/{id}`).
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  int  $serverId
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, int $serverId): JsonResponse
    {
        $server = Server::query()->where('account_id', ResourceJson::account($request)->id)->findOrFail($serverId);
        abort_unless($user->can('view', $server), 404);

        return response()->json(['data' => ResourceJson::server($server)]);
    }
}
