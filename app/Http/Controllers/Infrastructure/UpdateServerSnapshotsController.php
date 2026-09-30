<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\UpdateServerSnapshots;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class UpdateServerSnapshotsController
{
    /**
     * Save the snapshot setting (PUT) or take a snapshot now (POST), and return to the server's settings.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  UpdateServerSnapshots  $update
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, Server $server, UpdateServerSnapshots $update): RedirectResponse
    {
        $update->handle($user, $server, $request->isMethod('PUT') ? $request->boolean('snapshot_before_changes') : null);

        return to_route('infrastructure.servers.show', [$project, $server->id, 'tab' => 'settings'])->withFragment('snapshots')
            ->with('status', $request->isMethod('PUT') ? __('Saved.') : __('Taking a snapshot.'));
    }
}
