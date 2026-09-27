<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\AlertDestinationsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowAlertDestinationController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $destination, ProjectOverviewQuery $overview, AlertDestinationsQuery $destinations): View
    {
        $target = $destinations->find($project->account_id, $destination, withArchived: true);

        return view('monitoring.destination', [
            'overview' => $overview->handle($project, $user),
            'destination' => $target,
            'deliveries' => $target->deliveries()->latest('created_at')->limit(50)->get(),
            'members' => $destinations->recipients($project->account_id),
            'canManage' => $user->can('update', $project->account) && ! $target->trashed(),
            'issuedKey' => session('issued_key'),
        ]);
    }
}
