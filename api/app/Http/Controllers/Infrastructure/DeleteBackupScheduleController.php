<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\DeleteBackupSchedule;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class DeleteBackupScheduleController
{
    /**
     * Remove one of a website's backup schedules.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  Website  $website
     * @param  string  $schedule
     * @param  DeleteBackupSchedule  $delete
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, Website $website, string $schedule, DeleteBackupSchedule $delete): JsonResponse
    {
        $delete->handle($user, $website->backupSchedules()->findOrFail((int) $schedule));

        return response()->json(['redirect' => route('infrastructure.websites.show', [$project, $website->id, 'tab' => 'backups'], false), 'message' => __('Backup schedule removed.')]);
    }
}
