<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\RetryServerProvisioning;
use App\Models\Project;
use App\Models\User;
use App\Queries\Infrastructure\ServersQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class RetryServerProvisioningController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $server, ServersQuery $servers, RetryServerProvisioning $retry): RedirectResponse
    {
        $result = $retry->handle($project->account, $user, $servers->find($project->account_id, $server));
        $redirect = to_route('infrastructure.servers.show', [$project, (int) $server])->with('status', $result === false ? __('This server isn’t waiting for a retry.') : __('Provisioning the remaining stages.'));

        return is_string($result) ? $redirect->with('secrets', ['root' => $result]) : $redirect;
    }
}
