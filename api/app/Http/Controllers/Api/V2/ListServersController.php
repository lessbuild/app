<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Models\Server;
use App\Models\User;
use App\Support\Api\ResourceJson;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ListServersController
{
    /**
     * List the account's servers (`GET /api/v2/servers`).
     *
     * @param  Request  $request
     * @param  User  $user
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user): JsonResponse
    {
        $servers = Server::query()->where('account_id', ResourceJson::account($request)->id)->orderBy('name')->get()->filter(fn (Server $server): bool => $user->can('view', $server));

        return response()->json(['data' => $servers->map(ResourceJson::server(...))->values()]);
    }
}
