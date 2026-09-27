<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\RefreshServerLog;
use App\Models\Project;
use App\Models\User;
use App\Queries\Infrastructure\ServersQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class RefreshServerLogController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $server, string $type, ServersQuery $servers, RefreshServerLog $refresh): RedirectResponse
    {
        $queued = $refresh->handle($project->account, $user, $servers->find($project->account_id, $server), $type);

        return to_route('infrastructure.servers.show', [$project, (int) $server, 'log' => $type])->with('status', $queued ? __('Fetching the log.') : __('Logs are only available for active servers.'));
    }
}
