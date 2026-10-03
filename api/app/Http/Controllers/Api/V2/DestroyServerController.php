<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V2;

use App\Actions\Infrastructure\DeleteServer;
use App\Models\Server;
use App\Models\User;
use App\Support\Api\ResourceJson;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class DestroyServerController
{
    /**
     * Delete a server, at the cloud provider too (`DELETE /api/v2/servers/{id}`).
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  int  $serverId
     * @param  DeleteServer  $delete
     * @return Response
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, int $serverId, DeleteServer $delete): Response
    {
        $account = ResourceJson::account($request);
        $server = Server::query()->where('account_id', $account->id)->findOrFail($serverId);
        abort_unless($user->can('view', $server), 404);
        $delete->handle($account, $user, $server);

        return response()->noContent();
    }
}
