<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Actions\Security\GrantSshAccess;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class StoreSshGrantController
{
    /**
     * Give a member SSH access to a server and return to Security's servers.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  int  $server
     * @param  GrantSshAccess  $grant
     * @return RedirectResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, int $server, GrantSshAccess $grant): RedirectResponse
    {
        $data = $request->validate(['user_id' => ['required', 'string', 'size:26']]);
        $grant->handle($user, $project, $server, $data['user_id']);

        return to_route('security.servers', $project)->with('status', __('Access given. Their keys are being installed.'));
    }
}
