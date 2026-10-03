<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\PromoteReadReplica;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class PromoteReadReplicaController
{
    /**
     * Promote the read replica to a standalone database server and return to its replicas tab.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  PromoteReadReplica  $promote
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Server $server, PromoteReadReplica $promote): RedirectResponse
    {
        $data = $request->validate(['confirmation' => ['required', 'string', 'max:255']]);
        $promote->handle($user, $server, (string) $data['confirmation']);

        return to_route('infrastructure.servers.show', [$project, $server->id, 'tab' => 'replicas'])
            ->with('status', __('Promoting. Once it finishes, the server accepts writes; follow it in the command history.'));
    }
}
