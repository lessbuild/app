<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\DeleteServer;
use App\Models\Project;
use App\Models\User;
use App\Queries\Infrastructure\ServersQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteServerController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $server, ServersQuery $servers, DeleteServer $delete): RedirectResponse
    {
        $delete->handle($project->account, $user, $servers->find($project->account_id, $server));

        return to_route('infrastructure.servers', $project)->with('status', __('Server deleted.'));
    }
}
