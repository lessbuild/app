<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\DeleteOnCallRecord;
use App\Models\OnCallSchedule;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteOnCallScheduleController
{
    /**
     * Delete an on-call schedule and return to on-call.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  int  $schedule
     * @param  DeleteOnCallRecord  $delete
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, int $schedule, DeleteOnCallRecord $delete): RedirectResponse
    {
        $delete->handle($user, OnCallSchedule::query()->where('account_id', $project->account_id)->findOrFail($schedule));

        return to_route('monitoring.on-call', $project)->with('status', __('On-call schedule deleted.'));
    }
}
