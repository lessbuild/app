<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\RetryServerProvisioning;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class RetryServerProvisioningController
{
    /**
     * Provisions a failed server's remaining stages, showing a new root password once when one was issued.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Server  $server
     * @param  RetryServerProvisioning  $retry
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Server $server, RetryServerProvisioning $retry): RedirectResponse
    {
        $result = $retry->handle($project->account, $user, $server);
        $redirect = to_route('infrastructure.servers.show', [$project, $server->id])->with('status', $result === false ? __('This server isn’t waiting for a retry.') : __('Provisioning the remaining stages.'));

        return is_string($result) ? $redirect->with('secrets', ['root' => $result]) : $redirect;
    }
}
