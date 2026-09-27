<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\DeleteBackupSchedule;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteBackupScheduleController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, Website $website, string $schedule, DeleteBackupSchedule $delete): RedirectResponse
    {
        $delete->handle($user, $website->backupSchedules()->findOrFail((int) $schedule));

        return to_route('infrastructure.websites.show', [$project, $website->id, 'tab' => 'backups'])->with('status', __('Backup schedule removed.'));
    }
}
