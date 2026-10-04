<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\AddReadReplica;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AddReadReplicaController
{
    /**
     * Make another of the account's database servers a read replica of this one and return to its replicas tab.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  AddReadReplica  $add
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Server $server, AddReadReplica $add): JsonResponse
    {
        $data = $request->validate(['replica_server_id' => ['required', 'integer'], 'confirmation' => ['required', 'string', 'max:255']]);
        $replica = Server::query()->where('account_id', $server->account_id)->findOrFail((int) $data['replica_server_id']);
        $add->handle($user, $server, $replica, (string) $data['confirmation']);

        return response()->json(['redirect' => route('infrastructure.servers.show', [$project, $server->id, 'tab' => 'replicas'], false), 'message' => __(':name is copying this database. It starts serving reads once the copy finishes.', ['name' => $replica->name])]);
    }
}
