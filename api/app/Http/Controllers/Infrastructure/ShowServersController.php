<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use App\Queries\Infrastructure\ServerCreateFormQuery;
use App\Queries\Infrastructure\ServersQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Billing\Entitlements;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

/** The account's servers. They're shared, so every project shows the same list. */
final class ShowServersController
{
    /**
     * Show the account's servers and the plan's server limit. The "Create a server" modal loads its form when it
     * opens, except after a failed submit, when the form is rendered with the page so its errors show.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  ServersQuery  $servers
     * @param  Entitlements  $entitlements
     * @param  ServerCreateFormQuery  $createForm
     * @return View
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, ServersQuery $servers, Entitlements $entitlements, ServerCreateFormQuery $createForm): View
    {
        $canManage = $user->can('create', [Server::class, $project]);
        $retrying = $canManage && old('_modal') === 'create-server';

        return view('infrastructure.servers', [
            'createForm' => $retrying ? ['project' => $project, ...$createForm->handle($project, is_scalar(old('provider_id')) ? (string) old('provider_id') : null)] : null,
            'overview' => $overview->handle($project, $user),
            'servers' => $servers->handle($project->account_id),
            'limit' => $entitlements->for($project->account)->limit('infrastructure.servers.max'),
            'canManage' => $canManage,
        ]);
    }
}
