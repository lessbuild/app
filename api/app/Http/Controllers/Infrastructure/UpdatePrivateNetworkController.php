<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\SetPrivateNetworkTrust;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class UpdatePrivateNetworkController
{
    /**
     * Turn trust of the account's other servers over the private network on or off, and return to the Firewall tab.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  SetPrivateNetworkTrust  $trust
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Server $server, SetPrivateNetworkTrust $trust): JsonResponse
    {
        $on = $request->boolean('trust');
        $count = $trust->handle($user, $server, $on);

        return response()->json(['redirect' => route('infrastructure.servers.show', [$project, $server->id, 'tab' => 'firewall'], false), 'message' => $on
            ? trans_choice('The firewall now lets in :count other server over the private network.|The firewall now lets in :count other servers over the private network.', $count, ['count' => $count])
            : __('Other servers are no longer let in over the private network.')]);
    }
}
