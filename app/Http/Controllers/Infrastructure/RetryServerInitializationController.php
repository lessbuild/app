<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\RetryServerInitialization;
use App\Models\Project;
use App\Models\User;
use App\Queries\Infrastructure\ServersQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class RetryServerInitializationController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $server, ServersQuery $servers, RetryServerInitialization $retry): RedirectResponse
    {
        $queued = $retry->handle($project->account, $user, $servers->find($project->account_id, $server));

        return to_route('infrastructure.servers.show', [$project, (int) $server])->with('status', $queued ? __('Trying again.') : __('This server isn’t waiting for a retry.'));
    }
}
