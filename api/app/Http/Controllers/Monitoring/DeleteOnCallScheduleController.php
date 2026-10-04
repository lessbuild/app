<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\DeleteOnCallRecord;
use App\Models\OnCallSchedule;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DeleteOnCallScheduleController
{
    /**
     * Delete an on-call schedule and return to on-call.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  int  $schedule
     * @param  DeleteOnCallRecord  $delete
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, int $schedule, DeleteOnCallRecord $delete): JsonResponse
    {
        $delete->handle($user, OnCallSchedule::query()->where('account_id', $project->account_id)->findOrFail($schedule));

        return response()->json(['redirect' => route('monitoring.on-call', $project, false), 'message' => __('On-call schedule deleted.')]);
    }
}
