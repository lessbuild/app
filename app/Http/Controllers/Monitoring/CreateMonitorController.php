<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Http\Requests\Monitoring\MonitorRequest;
use App\Models\AlertDestination;
use App\Models\Project;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class CreateMonitorController
{
    /**
     * The new monitor form for the chosen check type (HTTP by default), with the account's destinations.
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview): View
    {
        $type = $request->string('check_type')->toString();

        return view('monitoring.monitor-form', [
            'overview' => $overview->handle($project, $user),
            'monitor' => null,
            'checkType' => array_key_exists($type, MonitorRequest::TYPES) ? $type : 'http',
            'destinations' => AlertDestination::query()->where('account_id', $project->account_id)->orderBy('name')->get(),
            'selectedDestinations' => [],
        ]);
    }
}
