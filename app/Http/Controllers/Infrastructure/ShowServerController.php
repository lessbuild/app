<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Models\Project;
use App\Models\User;
use App\Queries\Infrastructure\ServersQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Infrastructure\ServerProvisioningPlan;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowServerController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $server, ProjectOverviewQuery $overview, ServersQuery $servers, ServerProvisioningPlan $plan): View
    {
        $record = $servers->find($project->account_id, $server);

        return view('infrastructure.server', [
            'overview' => $overview->handle($project, $user),
            'server' => $record,
            'finalStage' => $plan->finalStage($record),
            'log' => $record->logSnapshots()->where('type', 'provisioning')->first(),
            'canManage' => $user->can('update', $project->account),
        ]);
    }
}
