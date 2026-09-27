<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Models\AlertDestination;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\AlertDestinationsQuery;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;

final class ShowAlertDestinationController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, AlertDestination $destination, ProjectOverviewQuery $overview, AlertDestinationsQuery $destinations): View
    {

        return view('monitoring.destination', [
            'overview' => $overview->handle($project, $user),
            'destination' => $destination,
            'deliveries' => $destination->deliveries()->latest('created_at')->limit(50)->get(),
            'members' => $destinations->recipients($project->account_id),
            'canManage' => $user->can('update', $destination),
            'issuedKey' => session('issued_key'),
        ]);
    }
}
