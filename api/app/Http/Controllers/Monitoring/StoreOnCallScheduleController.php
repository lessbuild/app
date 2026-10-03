<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SaveOnCallSchedule;
use App\Http\Requests\Monitoring\OnCallScheduleRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class StoreOnCallScheduleController
{
    /**
     * Add an on-call schedule to the account and return to on-call.
     *
     * @param  OnCallScheduleRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  SaveOnCallSchedule  $save
     * @return RedirectResponse
     */
    public function __invoke(OnCallScheduleRequest $request, #[CurrentUser] User $user, Project $project, SaveOnCallSchedule $save): RedirectResponse
    {
        $save->handle($user, $project->account, null, $request->schedule());

        return to_route('monitoring.on-call', $project)->with('status', __('On-call schedule added. Choose it as an email destination’s recipient to page whoever’s on call.'));
    }
}
