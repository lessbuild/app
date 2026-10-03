<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Actions\Security\RevokeSshAccess;
use App\Models\Project;
use App\Models\ServerSshGrant;
use App\Models\User;
use App\Queries\Security\ProjectServersQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteSshGrantController
{
    /**
     * Remove someone's SSH access to one of the project's servers and return to Security's servers.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  int  $server
     * @param  int  $grant
     * @param  ProjectServersQuery  $servers
     * @param  RevokeSshAccess  $revoke
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, int $server, int $grant, ProjectServersQuery $servers, RevokeSshAccess $revoke): RedirectResponse
    {
        abort_unless($servers->handle($project)->contains('id', $server), 404);
        $revoke->handle($user, $project, ServerSshGrant::query()->where('server_id', $server)->with(['user', 'server'])->findOrFail($grant));

        return to_route('security.servers', $project)->with('status', __('Access removed.'));
    }
}
