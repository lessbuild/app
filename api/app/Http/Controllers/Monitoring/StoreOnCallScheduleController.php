<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SaveOnCallSchedule;
use App\Http\Requests\Monitoring\OnCallScheduleRequest;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class StoreOnCallScheduleController
{
    /**
     * Add an on-call schedule to the account and return to on-call.
     *
     * @param  OnCallScheduleRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  SaveOnCallSchedule  $save
     * @return JsonResponse
     */
    public function __invoke(OnCallScheduleRequest $request, #[CurrentUser] User $user, Project $project, SaveOnCallSchedule $save): JsonResponse
    {
        $save->handle($user, $project->account, null, $request->schedule());

        return response()->json(['redirect' => route('monitoring.on-call', $project, false), 'message' => __('On-call schedule added. Choose it as an email destination’s recipient to page whoever’s on call.')]);
    }
}
