<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\AddOnCallOverride;
use App\Models\OnCallSchedule;
use App\Models\Project;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class StoreOnCallOverrideController
{
    /**
     * Put someone on call for a period, read in the schedule's time zone, and return to on-call.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  int  $schedule
     * @param  AddOnCallOverride  $add
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, int $schedule, AddOnCallOverride $add): JsonResponse
    {
        $record = OnCallSchedule::query()->where('account_id', $project->account_id)->findOrFail($schedule);
        $data = $request->validate(['user_id' => ['required', 'string'], 'starts_at' => ['required', 'date'], 'ends_at' => ['required', 'date']]);
        $add->handle($user, $record, (string) $data['user_id'], CarbonImmutable::parse((string) $data['starts_at'], $record->timezone), CarbonImmutable::parse((string) $data['ends_at'], $record->timezone));

        return response()->json(['redirect' => route('monitoring.on-call', $project, false), 'message' => __('Cover added.')]);
    }
}
