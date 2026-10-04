<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\SaveOnCallSchedule;
use App\Http\Requests\Monitoring\OnCallScheduleRequest;
use App\Models\OnCallSchedule;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class UpdateOnCallScheduleController
{
    /**
     * Save changes to an on-call schedule and return to on-call.
     *
     * @param  OnCallScheduleRequest  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  int  $schedule
     * @param  SaveOnCallSchedule  $save
     * @return JsonResponse
     */
    public function __invoke(OnCallScheduleRequest $request, #[CurrentUser] User $user, Project $project, int $schedule, SaveOnCallSchedule $save): JsonResponse
    {
        $record = OnCallSchedule::query()->where('account_id', $project->account_id)->findOrFail($schedule);
        $save->handle($user, $project->account, $record, $request->schedule());

        return response()->json(['redirect' => route('monitoring.on-call', $project, false), 'message' => __('On-call schedule saved.')]);
    }
}
