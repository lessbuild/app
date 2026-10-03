<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Models\User;
use App\Models\WebsiteBackupSchedule;
use Illuminate\Support\Facades\Gate;

final class DeleteBackupSchedule
{
    /**
     * Stop scheduled backups to a destination. Backups already taken stay.
     *
     * @param  User  $actor
     * @param  WebsiteBackupSchedule  $schedule
     * @return void
     */
    public function handle(User $actor, WebsiteBackupSchedule $schedule): void
    {
        Gate::forUser($actor)->authorize('update', $schedule->website);
        $schedule->delete();
    }
}
